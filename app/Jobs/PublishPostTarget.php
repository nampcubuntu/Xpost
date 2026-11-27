<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TargetStatus;
use App\Models\PostTarget;
use App\Services\Publishing\PostPublisher;
use App\Support\ProxyFailure;
use App\Support\TeamContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Publishes one target.
 *
 * Deliberately thin: all the policy lives in PostPublisher, so this class only
 * has to decide what the queue should do with the outcome. Retries are driven
 * by the publisher's own delay rather than the queue's backoff, because only
 * the publisher knows whether we are waiting on a daily cap (hours) or a
 * transient 503 (seconds).
 */
class PublishPostTarget implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // A cold card may need a probe and local encode before X processes its upload.
    public int $timeout = 1200;

    /** Failing to publish must not poison the whole batch. */
    public bool $failOnTimeout = false;

    public function __construct(
        public readonly int $targetId,
        public readonly int $teamId,
    ) {
        $this->onQueue('publish');
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            // Two workers must never post the same target twice.
            (new WithoutOverlapping("publish-target:{$this->targetId}"))
                ->releaseAfter(30)
                ->expireAfter(1260),
            new SkipIfBatchCancelled,
        ];
    }

    public function handle(PostPublisher $publisher): void
    {
        TeamContext::runAs($this->teamId, function () use ($publisher): void {
            $target = PostTarget::withoutGlobalScopes()->find($this->targetId);

            if ($target === null || $target->status->isTerminal()) {
                return;
            }

            $target->forceFill([
                'status' => TargetStatus::Publishing,
                'claimed_at' => now(),
                'claimed_by' => gethostname().':'.getmypid(),
            ])->save();

            $outcome = $publisher->publish($target);

            if ($outcome->isDeferred()) {
                Log::channel('xpost')->info('Publish deferred', [
                    'target_id' => $this->targetId,
                    'retry_in' => $outcome->retryInSeconds,
                    'reason' => $outcome->reason,
                ]);

                // The row is already back in `pending` with an available_at in
                // the future, so the dispatcher will pick it up again. Nothing
                // to re-queue here -- re-queuing would double-count the attempt.
                return;
            }

            if ($outcome->isFailed()) {
                Log::channel('xpost')->warning('Publish failed', [
                    'target_id' => $this->targetId,
                    'reason' => $outcome->reason,
                    'message' => $outcome->message,
                ]);
            }
        });
    }

    /** Deduplicate identical dispatches within the visibility window. */
    public function uniqueId(): string
    {
        return (string) $this->targetId;
    }

    public function failed(\Throwable $e): void
    {
        TeamContext::runAs($this->teamId, function () use ($e): void {
            $target = PostTarget::withoutGlobalScopes()
                ->with('account.proxy')
                ->whereKey($this->targetId)
                ->first();

            if ($target === null || $target->status !== TargetStatus::Publishing) {
                return;
            }

            /*
             * Named for the hop that failed, when it can be.
             *
             * Everything an account does goes through its proxy, so a curl
             * error here is as likely to be the proxy as X -- and "cURL error
             * 28" in the failed column sends whoever reads it to look at X,
             * which is the one place that cannot be the problem.
             */
            $explained = ProxyFailure::fromThrowable($e, $target->account?->proxy);

            $target->forceFill([
                'status' => TargetStatus::Failed->value,
                'error_code' => $explained === null ? 'job_failed' : 'proxy_unreachable',
                'error_message' => mb_substr($explained ?? $e->getMessage(), 0, 2000),
            ])->save();
        });
    }
}
