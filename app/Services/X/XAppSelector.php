<?php

declare(strict_types=1);

namespace App\Services\X;

use App\Models\XAccount;
use App\Models\XApp;
use Illuminate\Support\Facades\DB;

/**
 * Picks which developer app an account should live on.
 *
 * Assignment is sticky: once an account is bound to an app it stays there,
 * because X media_id values and some rate-limit state are app-scoped, and
 * shuffling accounts between apps mid-campaign invalidates both.
 */
class XAppSelector
{
    public function __construct(private readonly XRateLimiter $limiter) {}

    /**
     * Bind an account to the least-loaded app that still has headroom.
     * Returns null when the pool is full -- the caller should surface that as a
     * capacity problem rather than silently dropping the account.
     */
    public function assign(XAccount $account, bool $force = false): ?XApp
    {
        if (! $force && $account->x_app_id !== null) {
            return $account->app;
        }

        return DB::transaction(function () use ($account): ?XApp {
            $app = XApp::query()
                ->where('team_id', $account->team_id)
                ->acceptingAccounts()
                ->orderByRaw('(assigned_count / GREATEST(max_accounts, 1)) ASC')
                ->orderByDesc('weight')
                ->lockForUpdate()
                ->first();

            if ($app === null) {
                return null;
            }

            if ($account->x_app_id !== null && $account->x_app_id !== $app->id) {
                XApp::whereKey($account->x_app_id)->decrement('assigned_count');
            }

            $app->increment('assigned_count');
            $account->forceFill(['x_app_id' => $app->id])->save();

            return $app;
        });
    }

    /**
     * Apps this account may publish through, best first.
     *
     * The account's own app leads -- assignment is sticky because X media_id
     * values are app-scoped, so switching costs a re-upload. The siblings that
     * follow are what stops one exhausted app from parking every account on it
     * until midnight while the rest of the pool sits idle.
     *
     * @return list<XApp>
     */
    public function candidatesFor(XAccount $account): array
    {
        $own = $account->app ?? $this->assign($account);

        $candidates = [];

        if ($own !== null && $this->isUsable($own)) {
            $candidates[] = $own;
        }

        $siblings = XApp::query()
            ->where('team_id', $account->team_id)
            ->usable()
            ->when($own !== null, fn ($q) => $q->whereKeyNot($own->id))
            ->orderByDesc('weight')
            ->get()
            ->filter(fn (XApp $candidate) => $this->isUsable($candidate));

        return array_merge($candidates, $siblings->values()->all());
    }

    /** The single best app right now, or null when the pool has nothing to give. */
    public function resolveForPublishing(XAccount $account): ?XApp
    {
        return $this->candidatesFor($account)[0] ?? null;
    }

    private function isUsable(XApp $app): bool
    {
        return $app->is_active
            && $app->hasMonthlyBudgetLeft()
            && ! $app->isCoolingDown()
            && ! $this->limiter->appIsBlocked($app);
    }

    /** Spread a freshly imported batch of accounts across the pool. */
    public function rebalance(int $teamId, int $chunk = 500): int
    {
        $moved = 0;

        XAccount::query()
            ->where('team_id', $teamId)
            ->whereNull('x_app_id')
            ->chunkById($chunk, function ($accounts) use (&$moved): void {
                foreach ($accounts as $account) {
                    if ($this->assign($account) !== null) {
                        $moved++;
                    }
                }
            });

        return $moved;
    }
}
