<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Console\Commands\DispatchDueTargets;
use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Http\Resources\PostResource;
use App\Http\Resources\PostTargetResource;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Publishing\ComposerService;
use App\Services\Publishing\PostFanoutService;
use App\Services\Publishing\PublishingHalt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PostController extends Controller
{
    public function __construct(
        private readonly ComposerService $composer,
        private readonly PostFanoutService $fanout,
        private readonly PublishingHalt $halt,
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
        ]);
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
