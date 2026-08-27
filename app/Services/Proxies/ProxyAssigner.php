<?php

declare(strict_types=1);

namespace App\Services\Proxies;

use App\Models\Proxy;
use Illuminate\Support\Collection;

/**
 * Spreads accounts over the proxy pool, least-loaded first.
 *
 * The point of holding proxies at all is that a thousand accounts do not walk
 * out of one IP, so the assignment that matters is not "put this account on
 * that proxy" but "share these accounts out evenly". Doing that by hand is a
 * thousand dropdowns, and doing it badly -- everything on the first proxy --
 * is the same as having no proxies.
 *
 * The pool is read once and counted in memory for the rest of the request. A
 * query per account would be ten thousand SELECT ... FOR UPDATE on a full
 * selection; worse, reading assigned_count from the database each time returns
 * the same answer until the observer has written, so every account would pick
 * the same proxy.
 *
 * The trade-off is that two spreads running at once can each fill the same
 * headroom. max_accounts is a policy, not an invariant -- overshooting it by a
 * few is not worth holding a lock across ten thousand rows.
 */
class ProxyAssigner
{
    /** @var Collection<int, Proxy>|null */
    private ?Collection $pool = null;

    private ?int $poolTeamId = null;

    /** @var list<int>|null */
    private ?array $only = null;

    /** How many accounts asked for a proxy after the pool was full. */
    public int $unassigned = 0;

    /**
     * Spread over these proxies rather than over the whole pool.
     *
     * What the proxy page asks for: a few proxies were picked, and the accounts
     * are chosen to go behind them. The alternative -- start from the accounts
     * and let the pool decide -- is the same operation from the other end, and
     * it would be a second implementation of the same sort.
     *
     * @param  list<int>  $proxyIds
     */
    public function restrictTo(array $proxyIds): self
    {
        $this->only = $proxyIds;
        $this->pool = null;

        return $this;
    }

    /**
     * The emptiest proxy that still has room, or null when the pool is full.
     */
    public function next(int $teamId): ?Proxy
    {
        if ($this->pool === null || $this->poolTeamId !== $teamId) {
            $this->poolTeamId = $teamId;

            // withoutGlobalScopes with an explicit team: the account being
            // assigned is the authority on which team this is, not the request.
            $this->pool = Proxy::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->assignable()
                ->when($this->only !== null, fn ($query) => $query->whereKey($this->only))
                ->get(['id', 'assigned_count', 'max_accounts']);
        }

        $proxy = $this->pool
            ->filter(fn (Proxy $candidate) => $candidate->assigned_count < $candidate->max_accounts)
            ->sortBy(fn (Proxy $candidate) => $candidate->assigned_count / max($candidate->max_accounts, 1))
            ->first();

        if ($proxy === null) {
            $this->unassigned++;

            return null;
        }

        /*
         * Counted here as well as by XAccountObserver. The observer writes the
         * real column; this is the in-request view the next account is chosen
         * from, and without it the sort returns the same proxy every time.
         */
        $proxy->assigned_count++;

        return $proxy;
    }
}
