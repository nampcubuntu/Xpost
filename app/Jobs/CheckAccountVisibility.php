<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\VisibilityCheck;
use App\Services\X\ShadowbanChecker;
use App\Support\TeamContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * One handle's visibility, checked on the queue.
 *
 * Queued rather than run in the request because the check is two Sorsa calls
 * and takes three to five seconds -- and a list of a hundred is minutes, which
 * is not something to hold an HTTP request open for.
 *
 * The throttle is shared across every worker: Horizon runs several, and a
 * per-process limit would be several times the plan's allowance.
 */
class CheckAccountVisibility implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly int $checkId,
        public readonly int $teamId,
    ) {
        $this->onQueue('checks');
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        /*
         * report() is not optional here. Without it the middleware swallows
         * the exception and releases the job, so the only trace left is
         * "attempted too many times" -- 143 of those in the log, not one of
         * them naming what actually broke.
         */
        return [(new ThrottlesExceptions(5, 60))->report()];
    }

    public function handle(ShadowbanChecker $checker): void
    {
        if (! $checker->isConfigured()) {
            return;
        }

        TeamContext::runAs($this->teamId, function () use ($checker): void {
            $row = VisibilityCheck::query()->find($this->checkId);

            if ($row === null) {
                return;
            }

            /*
             * Two requests per handle against a flat twenty a second, so ten
             * handles a second across the whole install. Redis-backed rather
             * than per-worker: Horizon runs several, and a local counter would
             * let each of them have the full allowance.
             */
            Redis::throttle('sorsa')
                ->allow(max(1, (int) (config('xpost.links.sorsa.requests_per_second', 20) / 2)))
                ->every(1)
                ->block(30)
                ->then(function () use ($row, $checker): void {
                    $result = $checker->check($row->handle);

                    $row->forceFill([
                        'state' => $result['state'],
                        'checked_at' => $result['checked_at'],
                        'detail' => $result['detail'],
                    ])->save();
                }, function (): void {
                    /*
                     * Could not get a slot within the block window. Released
                     * rather than failed: the handle is no worse off for being
                     * checked a minute later, and a failed job here would need
                     * someone to notice and retry it.
                     */
                    $this->release(30);
                });
        });
    }

    /**
     * A check that died still has to leave the row saying something.
     *
     * Without this the row stays blank, and blank is what a handle looks
     * like before it has ever been checked -- so a failure was
     * indistinguishable from waiting, and the page sat there promising a
     * result that was never coming.
     */
    public function failed(Throwable $e): void
    {
        TeamContext::runAs($this->teamId, function () use ($e): void {
            VisibilityCheck::query()->whereKey($this->checkId)->update([
                'state' => 'unknown',
                'checked_at' => now(),
                'detail' => json_encode([
                    'search' => 'unknown',
                    'search_suggestion' => 'unknown',
                    'replies' => 'unknown',
                    'error' => class_basename($e).': '.$e->getMessage(),
                ]),
            ]);
        });
    }
}
