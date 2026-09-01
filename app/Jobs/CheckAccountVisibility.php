<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\XAccount;
use App\Services\X\ShadowbanChecker;
use App\Support\TeamContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Facades\Redis;

/**
 * One account's visibility, checked on the queue.
 *
 * Queued rather than run in the request because a sweep is two Sorsa calls per
 * account and the plan allows twenty requests a second flat -- a thousand
 * accounts is a hundred seconds at the very best, which is not something to
 * hold an HTTP request open for.
 *
 * The throttle is the same number, shared across every worker: Horizon runs
 * several, and a per-process limit would be several times the allowance.
 */
class CheckAccountVisibility implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly int $accountId,
        public readonly int $teamId,
    ) {
        $this->onQueue('checks');
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new ThrottlesExceptions(5, 60)];
    }

    public function handle(ShadowbanChecker $checker): void
    {
        if (! $checker->isConfigured()) {
            return;
        }

        TeamContext::runAs($this->teamId, function () use ($checker): void {
            $account = XAccount::query()->find($this->accountId);

            if ($account === null) {
                return;
            }

            /*
             * Two requests per account against a flat twenty a second, so ten
             * accounts a second across the whole install. Redis-backed rather
             * than per-worker: Horizon runs several, and a local counter would
             * let each of them have the full allowance.
             */
            Redis::throttle('sorsa')
                ->allow((int) config('xpost.links.sorsa.requests_per_second', 20) / 2)
                ->every(1)
                ->block(30)
                ->then(function () use ($account, $checker): void {
                    $result = $checker->check($account);

                    $account->forceFill([
                        'visibility_state' => $result['state'],
                        'visibility_checked_at' => $result['checked_at'],
                        'visibility_detail' => $result['detail'],
                    ])->save();
                }, function (): void {
                    /*
                     * Could not get a slot within the block window. Released
                     * rather than failed: the account is no worse off for being
                     * checked a minute later, and a failed job here would need
                     * someone to notice and retry it.
                     */
                    $this->release(30);
                });
        });
    }
}
