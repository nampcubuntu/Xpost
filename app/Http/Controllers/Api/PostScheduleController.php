<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\DestinationType;
use App\Enums\ReplySettings;
use App\Enums\ScheduleMode;
use App\Enums\VariantStrategy;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostSchedule;
use App\Services\Publishing\AudienceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PostScheduleController extends Controller
{
    public function __construct(private readonly AudienceResolver $audience) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Post::class);

        return response()->json([
            'data' => PostSchedule::query()
                ->withCount('posts')
                ->latest('id')
                ->get()
                ->map(fn (PostSchedule $s) => $this->present($s)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Post::class);

        $schedule = PostSchedule::create($this->validated($request) + [
            'created_by' => $request->user()->id,
        ]);

        $schedule->refreshNextRun();

        return response()->json($this->present($schedule->fresh()), 201);
    }

    public function show(PostSchedule $postSchedule): JsonResponse
    {
        $this->authorize('viewAny', Post::class);

        return response()->json($this->present($postSchedule->loadCount('posts')));
    }

    public function update(Request $request, PostSchedule $postSchedule): JsonResponse
    {
        $this->authorize('create', Post::class);

        $postSchedule->fill($this->validated($request, $postSchedule))->save();

        // Times, days or the active flag may all have moved the next firing.
        $postSchedule->refreshNextRun();

        return response()->json($this->present($postSchedule->fresh()));
    }

    public function destroy(PostSchedule $postSchedule): JsonResponse
    {
        $this->authorize('create', Post::class);

        $postSchedule->delete();

        return response()->json(status: 204);
    }

    /** Pause or resume without losing the definition. */
    public function toggle(PostSchedule $postSchedule): JsonResponse
    {
        $this->authorize('create', Post::class);

        $postSchedule->forceFill(['is_active' => ! $postSchedule->is_active])->save();
        $postSchedule->refreshNextRun();

        return response()->json($this->present($postSchedule->fresh()));
    }

    /** What a saved schedule will cost and produce. */
    public function preview(PostSchedule $postSchedule): JsonResponse
    {
        $this->authorize('viewAny', Post::class);

        return response()->json($this->quote(
            $postSchedule->template,
            $postSchedule->postsPerDay(),
            $postSchedule->team_id,
        ) + ['next_run_at' => $postSchedule->next_run_at?->toIso8601String()]);
    }

    /**
     * The same quote for a schedule that has not been saved yet.
     *
     * The editor needs the number while the times are still being picked --
     * finding out a schedule costs $4,000 a month after saving it is too late
     * to be useful.
     */
    public function previewDraft(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Post::class);

        $data = $request->validate([
            'template' => ['required', 'array'],
            'times' => ['array'],
            'times.*' => ['string'],
        ]);

        $draft = new PostSchedule(['times' => $data['times'] ?? [], 'is_active' => true]);

        return response()->json($this->quote(
            $data['template'],
            $draft->postsPerDay(),
            (int) $request->user()->current_team_id,
        ));
    }

    /**
     * Audience size times slots per day, priced.
     *
     * Worth showing before saving: at X's pay-per-use rates a link turns a
     * $0.015 post into a $0.20 one, and a daily schedule multiplies that by the
     * audience every single day.
     */
    private function quote(array $template, int $timesPerDay, int $teamId): array
    {
        $draft = new Post($template + ['audience' => $template['audience'] ?? ['mode' => 'all']]);
        $draft->team_id = $teamId;

        $accounts = $this->audience->count($draft);
        $postsPerDay = $accounts * $timesPerDay;

        /*
         * Publishing at each account's local hour is only as good as the
         * timezones on file. An account with none silently falls back to the
         * schedule's own timezone, so the editor says how many that is rather
         * than letting the user assume the pool is fully mapped.
         */
        $perAccountLocal = ($template['schedule_mode'] ?? null) === ScheduleMode::PerAccountTimezone->value;
        $missingTimezone = $perAccountLocal
            ? (clone $this->audience->query($draft))->whereNull('timezone')->count()
            : 0;

        $base = (float) config('xpost.pricing.post_usd');
        $hasLink = ($template['destination_type'] ?? 'none') === 'website';
        $unit = $hasLink ? (float) config('xpost.pricing.post_with_link_usd') : $base;

        return [
            'accounts' => $accounts,
            'times_per_day' => $timesPerDay,
            'posts_per_day' => $postsPerDay,
            'posts_per_month' => $postsPerDay * 30,
            'per_account_local' => $perAccountLocal,
            'accounts_without_timezone' => $missingTimezone,
            'has_link' => $hasLink,
            'unit_price_usd' => $unit,
            // Sent alongside so the UI can say what dropping the link would save.
            'base_price_usd' => $base,
            'cost_per_day_usd' => round($postsPerDay * $unit, 2),
            'cost_per_month_usd' => round($postsPerDay * $unit * 30, 2),
        ];
    }

    private function present(PostSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'name' => $schedule->name,
            'template' => $schedule->template,
            'times' => $schedule->times,
            'days_of_week' => $schedule->days_of_week,
            'timezone' => $schedule->timezone,
            'starts_on' => $schedule->starts_on?->toDateString(),
            'ends_on' => $schedule->ends_on?->toDateString(),
            'is_active' => $schedule->is_active,
            'catch_up_minutes' => $schedule->catch_up_minutes,
            'posts_per_day' => $schedule->postsPerDay(),
            'runs_count' => $schedule->runs_count,
            'posts_count' => $schedule->posts_count ?? null,
            'last_run_at' => $schedule->last_run_at?->toIso8601String(),
            'next_run_at' => $schedule->next_run_at?->toIso8601String(),
            'last_error' => $schedule->last_error,
        ];
    }

    private function validated(Request $request, ?PostSchedule $existing = null): array
    {
        $required = $existing ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'campaign_id' => ['nullable', 'integer', Rule::exists('campaigns', 'id')],

            /*
             * The composer payload. Every key the schedule should keep needs a
             * rule of its own: validated() drops anything unmentioned, so a
             * template declared only as `array` came back holding nothing but
             * the body -- the destination link vanished on save.
             *
             * The deep validation still happens later, when the generator hands
             * the payload to ComposerService through StorePostRequest's rules.
             */
            'template' => [$required, 'array'],
            'template.body' => ['nullable', 'string'],
            'template.reply_settings' => ['nullable', Rule::enum(ReplySettings::class)],
            'template.destination_type' => ['nullable', Rule::enum(DestinationType::class)],
            'template.website_url' => ['nullable', 'url:http,https', 'max:2048'],
            'template.headline' => ['nullable', 'string', 'max:70'],
            'template.variant_strategy' => ['nullable', Rule::enum(VariantStrategy::class)],
            'template.media_ids' => ['nullable', 'array', 'max:'.config('xpost.composer.max_media_per_post')],
            'template.media_ids.*' => ['integer'],
            'template.is_ai_generated' => ['nullable', 'boolean'],

            // Whether every account fires at one global instant, or at this
            // wall-clock hour in its own timezone.
            'template.schedule_mode' => ['nullable', Rule::enum(ScheduleMode::class)],

            'template.audience' => [$required, 'array'],
            'template.audience.mode' => [$required, 'in:all,groups,accounts,filter'],
            'template.audience.groups' => ['nullable', 'array'],
            'template.audience.groups.*' => ['integer'],
            'template.audience.accounts' => ['nullable', 'array'],
            'template.audience.accounts.*' => ['integer'],
            'template.audience.filters' => ['nullable', 'array'],
            'template.audience.shuffle' => ['nullable', 'boolean'],
            'template.audience.limit' => ['nullable', 'integer', 'min:1'],

            'times' => [$required, 'array', 'min:1', 'max:24'],
            'times.*' => ['regex:/^\d{1,2}:\d{2}$/'],
            'days_of_week' => ['nullable', 'array', 'max:7'],
            'days_of_week.*' => ['integer', 'between:1,7'],
            'timezone' => ['nullable', 'timezone:all_with_bc'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'is_active' => ['boolean'],
            'catch_up_minutes' => ['integer', 'min:0', 'max:1440'],
        ]);
    }
}
