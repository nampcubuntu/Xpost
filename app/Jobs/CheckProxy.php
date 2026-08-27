<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Proxy;
use App\Services\Proxies\ProxyChecker;
use App\Support\TeamContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One proxy checked off the request cycle.
 *
 * A dead proxy takes fifteen seconds to admit it, so checking a selection of a
 * hundred inline would hold an HTTP request open for the better part of an
 * hour. The page instead marks them queued and watches the verdicts arrive.
 *
 * Unique per proxy: clicking Kiểm tra twice, or having it in two selections,
 * should not mean two connections opened through the same proxy at once.
 */
class CheckProxy implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** One attempt. A proxy that did not answer has answered. */
    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $proxyId,
        public readonly int $teamId,
    ) {
        $this->onQueue('proxies');
    }

    public function uniqueId(): string
    {
        return (string) $this->proxyId;
    }

    public function handle(ProxyChecker $checker): void
    {
        TeamContext::runAs($this->teamId, function () use ($checker): void {
            $proxy = Proxy::withoutGlobalScopes()->find($this->proxyId);

            if ($proxy !== null) {
                $checker->check($proxy);
            }
        });
    }

    /**
     * A failed job must still clear the queued mark.
     *
     * Otherwise the row spins in the page forever, and the one thing worse than
     * a check that failed is a check that appears to still be running.
     */
    public function failed(?\Throwable $exception): void
    {
        TeamContext::runAs($this->teamId, function (): void {
            Proxy::withoutGlobalScopes()
                ->whereKey($this->proxyId)
                ->update(['check_queued_at' => null, 'health_status' => 'dead']);
        });
    }
}
