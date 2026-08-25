<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Services\Publishing\PostFanoutService;
use App\Support\TeamContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Expands a post into targets off the request cycle.
 *
 * Fanning 10k rows out inline would hold an HTTP request open for seconds; the
 * composer instead gets an immediate "scheduled" response and watches the
 * counters fill in.
 */
class FanOutPost implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $postId,
        public readonly int $teamId,
    ) {
        $this->onQueue('fanout');
    }

    public function handle(PostFanoutService $fanout): void
    {
        TeamContext::runAs($this->teamId, function () use ($fanout): void {
            $post = Post::withoutGlobalScopes()->find($this->postId);

            if ($post === null || $post->status->isTerminal()) {
                return;
            }

            $created = $fanout->fanOut($post);

            Log::channel('xpost')->info('Post fanned out', [
                'post_id' => $this->postId,
                'targets' => $created,
            ]);

            if ($created === 0) {
                $post->forceFill([
                    'status' => PostStatus::Failed,
                ])->save();
            }
        });
    }

    public function uniqueId(): string
    {
        return (string) $this->postId;
    }
}
