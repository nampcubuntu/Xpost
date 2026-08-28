<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Console\Commands\DispatchDueTargets;
use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Http\Resources\PostResource;
use App\Http\Resources\PostTargetResource;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\Team;
use App\Services\Publishing\ComposerService;
use App\Services\Publishing\PostFanoutService;
use App\Services\Publishing\PublishingHalt;
use App\Services\Publishing\SpendGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PostController extends Controller
{
    public function __construct(
        private readonly ComposerService $composer,
        private readonly PostFanoutService $fanout,
        private readonly PublishingHalt $halt,
        private readonly SpendGuard $spend,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Post::class);

        $posts = Post::query()
            ->with([
                'campaign', 'author', 'media',
                'sampleTarget.account:id,username,name,avatar_url',
            ])
            ->status($request->query('status'))
            ->type($request->query('type'))
            ->when($request->query('campaign_id'), fn ($q, $id) => $q->where('campaign_id', $id))
            ->when($request->query('search'), fn ($q, $term) => $q->where(
                fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('body', 'like', "%{$term}%")
            ))
            ->latest('id')
            ->paginate(min((int) $request->query('per_page', 25), 100))
            ->withQueryString();

        /*
         * Whether the scheduler is alive, alongside the posts it is meant to be
         * publishing. It runs as a separate process from Horizon, so it can
         * stop while every queue worker stays up and healthy -- and then posts
         * simply sit in `pending` with nothing anywhere saying why. This page is
         * where that is noticed, so this is where the answer belongs.
         */
        return PostResource::collection($posts)->additional([
            'scheduler_last_run_at' => DispatchDueTargets::lastRunAt(),
            // Set when X refused a post for want of credit, which refuses every
            // account at once and so is a property of the team, not the post.
            'halt' => $this->halt->current((int) $request->user()->current_team_id),
            // Shown where the spending happens, rather than on a settings page
            // nobody opens until after the invoice.
            'budget' => $this->budgetFor((int) $request->user()->current_team_id),
        ]);
    }

    /** @return array{spent_usd: float, budget_usd: float|null, remaining_usd: float|null} */
    private function budgetFor(int $teamId): array
    {
        $spentCents = $this->spend->spentCentsToday($teamId);
        $budgetCents = $this->spend->budgetCentsFor($teamId);

        return [
            'spent_usd' => round($spentCents / 100, 2),
            // budgetCentsFor answers -1 for "no ceiling", which is a value the
            // Lua script can compare; the UI wants the absence.
            'budget_usd' => $budgetCents < 0 ? null : round($budgetCents / 100, 2),
            'remaining_usd' => $budgetCents < 0 ? null : round(max(0, $budgetCents - $spentCents) / 100, 2),
        ];
    }

    /**
     * Set or clear the team's daily ceiling.
     *
     * Null clears it, and that is the default rather than an oversight: a limit
     * nobody chose, silently stopping a campaign overnight, is worse than none.
     */
    public function updateBudget(Request $request): JsonResponse
    {
        /*
         * Not viewAny. That gate is "you are on a team", which would let any
         * member raise or remove the ceiling on the team's spending -- and the
         * spending is real money at twenty cents a post.
         */
        abort_unless($request->user()->isAtLeast($request->user()->current_team_id, TeamRole::Admin), 403);

        $data = $request->validate([
            'daily_budget_usd' => ['present', 'nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $team = Team::query()->findOrFail($request->user()->current_team_id);
        $team->forceFill(['daily_budget_usd' => $data['daily_budget_usd']])->save();

        return response()->json(['data' => $this->budgetFor((int) $team->id)]);
    }

    /**
     * Resume publishing after a halt.
     *
     * Only a human can say the money is back -- Xpost cannot read the balance,
     * X exposes no endpoint for it. Clearing early costs one refused post and
     * another halt, which is cheap enough that asking is worse than trying.
     */
    public function resume(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Post::class);

        $this->halt->release((int) $request->user()->current_team_id);

        return response()->json(['halted' => false]);
    }

    public function store(StorePostRequest $request): JsonResponse
    {
        $post = $this->composer->create($request->validated(), $request->user()->id);

        return PostResource::make($post)
            ->additional(['preview' => $this->composer->preview($post)])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Post $post): PostResource
    {
        $this->authorize('view', $post);

        return PostResource::make($post->load(['campaign', 'author', 'media', 'variants']));
    }

    public function update(UpdatePostRequest $request, Post $post): PostResource
    {
        return PostResource::make($this->composer->update($post, $request->validated()));
    }

    public function destroy(Post $post): JsonResponse
    {
        $this->authorize('delete', $post);

        // Pending work is cancelled first; deleting the post while targets are
        // in flight would leave the queue holding jobs for a row that is gone.
        $this->fanout->cancel($post);
        $post->delete();

        return response()->json(status: 204);
    }

    /**
     * Delete a selection in one go.
     *
     * The list is where posts are cleared out, and clearing out a test run
     * means the same confirm dialog forty times. Each one is cancelled and
     * deleted exactly as a single delete is -- pending targets first, so the
     * queue is not left holding jobs for rows that are gone.
     *
     * Ids are resolved through the team scope rather than trusted: they arrive
     * from a browser, and one from another team must find nothing.
     */
    public function destroyMany(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Post::class);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
        ]);

        $posts = Post::query()->whereKey($data['ids'])->get();
        $deleted = 0;
        $published = 0;

        foreach ($posts as $post) {
            if ($request->user()->cannot('delete', $post)) {
                continue;
            }

            $published += $post->targets_published;

            $this->fanout->cancel($post);
            $post->delete();
            $deleted++;
        }

        return response()->json([
            'deleted' => $deleted,
            // What is still on X afterwards. Deleting here never reaches a
            // timeline, and the count is the only place that says so.
            'published' => $published,
        ]);
    }

    /** Move a draft to scheduled (or fire it now) and start fan-out. */
    public function publish(Post $post): JsonResponse
    {
        $this->authorize('publish', $post);

        if (! $post->status->isEditable()) {
            return response()->json([
                'message' => __('xpost.errors.post_not_editable', ['status' => $post->status->label()]),
            ], 422);
        }

        $post = $this->composer->schedule($post);

        return PostResource::make($post->fresh())
            ->additional(['preview' => $this->composer->preview($post)])
            ->response();
    }

    /** Stop everything not yet sent. Already-published targets are untouched. */
    public function cancel(Post $post): JsonResponse
    {
        $this->authorize('cancel', $post);

        $cancelled = $this->fanout->cancel($post);

        return response()->json([
            'cancelled' => $cancelled,
            'post' => PostResource::make($post->fresh()),
        ]);
    }

    /** Audience size and delivery window, without writing anything. */
    public function preview(Request $request, Post $post): JsonResponse
    {
        $this->authorize('view', $post);

        return response()->json($this->composer->preview($post));
    }

    /**
     * A copy of this post, as a new draft, ready to edit.
     *
     * Reusing a campaign is the ordinary case, and the alternative is retyping
     * it beside the original on a second screen.
     */
    public function duplicate(Request $request, Post $post): JsonResponse
    {
        $this->authorize('create', Post::class);
        $this->authorize('view', $post);

        $copy = $this->composer->duplicate($post->load(['media', 'variants']), $request->user()->id);

        return PostResource::make($copy)->response()->setStatusCode(201);
    }

    /** Per-account delivery log for one post. */
    public function targets(Request $request, Post $post): AnonymousResourceCollection
    {
        $this->authorize('view', $post);

        $targets = PostTarget::query()
            ->where('post_id', $post->id)
            ->with('account:id,username,avatar_url')
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderBy('id')
            ->paginate(min((int) $request->query('per_page', 50), 200))
            ->withQueryString();

        return PostTargetResource::collection($targets);
    }

    /** Requeue the failed targets of a post without touching the successes. */
    public function retryFailed(Post $post): JsonResponse
    {
        $this->authorize('publish', $post);

        $requeued = PostTarget::query()
            ->where('post_id', $post->id)
            ->where('status', TargetStatus::Failed->value)
            ->update([
                'status' => TargetStatus::Pending->value,
                'attempts' => 0,
                'available_at' => now(),
                'error_code' => null,
                'error_message' => null,
                'updated_at' => now(),
            ]);

        $post->forceFill([
            'status' => $requeued > 0 ? PostStatus::Publishing : $post->status,
            'targets_failed' => max(0, $post->targets_failed - $requeued),
            'targets_pending' => $post->targets_pending + $requeued,
        ])->save();

        return response()->json(['requeued' => $requeued]);
    }
}
