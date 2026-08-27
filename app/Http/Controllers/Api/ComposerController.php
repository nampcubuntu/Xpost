<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\AccountStatus;
use App\Enums\DestinationType;
use App\Enums\ReplySettings;
use App\Enums\ScheduleMode;
use App\Enums\VariantStrategy;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\Publishing\AudienceResolver;
use App\Services\Publishing\DuplicateContentCheck;
use App\Services\Publishing\SchedulePlanner;
use App\Services\Publishing\SpintaxRenderer;
use App\Services\Publishing\TweetTextWeigher;
use App\Support\WallClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only helpers the composer calls while the user is still typing.
 * Nothing here writes, so it is safe to hit on every keystroke (debounced).
 */
class ComposerController extends Controller
{
    public function __construct(private readonly DuplicateContentCheck $duplicates) {}

    /** Enum values + limits, so the SPA never hard-codes them. */
    public function options(): JsonResponse
    {
        return response()->json([
            'reply_settings' => collect(ReplySettings::cases())
                ->map(fn (ReplySettings $c) => ['value' => $c->value, 'label' => $c->label()]),
            'destination_types' => collect(DestinationType::cases())
                ->map(fn (DestinationType $c) => ['value' => $c->value]),
            'schedule_modes' => collect(ScheduleMode::cases())
                ->map(fn (ScheduleMode $c) => ['value' => $c->value, 'needs_window' => $c->needsWindow()]),
            'variant_strategies' => collect(VariantStrategy::cases())
                ->map(fn (VariantStrategy $c) => ['value' => $c->value, 'uses_variants' => $c->usesVariantTable()]),
            'account_statuses' => collect(AccountStatus::cases())
                ->map(fn (AccountStatus $c) => ['value' => $c->value, 'label' => $c->label()]),
            'limits' => config('xpost.composer'),
        ]);
    }

    /**
     * Weighted length of the draft, matching what X will count.
     * The URL is included because a destination link is appended to the tweet.
     */
    public function measure(Request $request): JsonResponse
    {
        $data = $request->validate([
            'body' => ['nullable', 'string'],
            'website_url' => ['nullable', 'string'],
        ]);

        $body = (string) ($data['body'] ?? '');
        $combined = trim($body.' '.($data['website_url'] ?? ''));

        return response()->json([
            'weighted_length' => TweetTextWeigher::weight($combined),
            'remaining' => TweetTextWeigher::remaining($combined),
            'max' => TweetTextWeigher::MAX_WEIGHTED_LENGTH,
            'urls' => TweetTextWeigher::urls($body),
            'permutations' => (new SpintaxRenderer)->permutationCount($body),
        ]);
    }

    /** Sample renders of a spintax template, so the user can see what ships. */
    public function sampleVariants(Request $request, SpintaxRenderer $spintax): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string'],
            'count' => ['integer', 'min:1', 'max:20'],
        ]);

        $count = (int) ($data['count'] ?? 5);

        return response()->json([
            'samples' => collect(range(1, $count))
                ->map(fn (int $i) => $spintax->render($data['body'], $i))
                ->unique()
                ->values(),
        ]);
    }

    /**
     * How many accounts an audience selection resolves to, and when the blast
     * would finish -- without creating a post.
     */
    public function estimate(Request $request, AudienceResolver $audience, SchedulePlanner $planner): JsonResponse
    {
        $data = $request->validate([
            'audience' => ['required', 'array'],
            'audience.mode' => ['required', 'in:all,groups,accounts,filter'],
            'schedule_mode' => ['nullable', 'string'],
            'timezone' => ['nullable', 'timezone:all_with_bc'],
            'scheduled_at' => ['nullable', 'date'],
            'window_ends_at' => ['nullable', 'date'],
            'jitter_seconds' => ['nullable', 'integer', 'min:0'],
            'per_minute_cap' => ['nullable', 'integer', 'min:1'],
            // For the duplicate check, which asks what this content has already
            // been sent to rather than how many accounts it is heading for.
            'media_ids' => ['nullable', 'array'],
            'media_ids.*' => ['integer'],
            'website_url' => ['nullable', 'string', 'max:2048'],
            'post_id' => ['nullable', 'integer'],
        ]);

        // Same wall-clock reading as StorePostRequest, or the estimate quotes a
        // delivery window the real post will not keep to.
        // `??` rather than `?:`: preventAccessingMissingAttributes throws on a
        // model hydrated without the column, and only the null-coalesce goes
        // through __isset instead of __get.
        $timezone = $data['timezone'] ?? ($request->user()->timezone ?? null) ?: config('app.timezone');

        // A throwaway model: never saved, just enough for the resolver and
        // planner to read the same fields they would read on a real post.
        $draft = new Post([
            'audience' => $data['audience'],
            'schedule_mode' => $data['schedule_mode'] ?? ScheduleMode::Immediate->value,
            'timezone' => $timezone,
            'scheduled_at' => WallClock::toAppTimezone($data['scheduled_at'] ?? null, $timezone),
            'window_ends_at' => WallClock::toAppTimezone($data['window_ends_at'] ?? null, $timezone),
            'jitter_seconds' => $data['jitter_seconds'] ?? 0,
            'per_minute_cap' => $data['per_minute_cap'] ?? null,
        ]);
        $draft->team_id = $request->user()->current_team_id;

        $count = $audience->count($draft);
        $window = $planner->summarise($draft, max(1, $count));

        return response()->json([
            'accounts' => $count,
            'first_at' => $window['first']->toIso8601String(),
            'last_at' => $window['last']->toIso8601String(),
            'duration_seconds' => $window['duration_seconds'],
            'exceeds_limit' => $count > config('xpost.publishing.max_targets_per_post'),
            /*
             * How much of this exact content has already gone out. Shown here
             * rather than after publishing, because that is the only side of
             * the decision where it is still a decision.
             */
            'duplicate' => $this->duplicates->for(
                array_map('intval', $data['media_ids'] ?? []),
                $data['website_url'] ?? null,
                isset($data['post_id']) ? (int) $data['post_id'] : null,
            ),
        ]);
    }
}
