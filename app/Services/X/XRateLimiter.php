<?php

declare(strict_types=1);

namespace App\Services\X;

use App\Models\XAccount;
use App\Models\XApp;
use App\Services\X\DTO\RateLimitSnapshot;
use Illuminate\Support\Facades\Redis;

/**
 * Two-level budget enforcement, both held in Redis so every worker on every
 * node sees the same counters.
 *
 *   account:{id}  -- protects an individual account from looking like a bot
 *   app:{id}      -- protects the shared developer app from its tier cap
 *
 * Both are fixed-window counters incremented atomically. A fixed window is the
 * right shape here because X publishes its own limits as fixed windows, and it
 * lets one INCR + one EXPIRE settle the whole decision.
 */
class XRateLimiter
{
    /**
     * INCR the key, set the TTL only on first write, and return
     * {allowed, current, ttl} in one round trip.
     */
    private const CONSUME = <<<'LUA'
        local current = redis.call('INCR', KEYS[1])
        if current == 1 then
            redis.call('EXPIRE', KEYS[1], ARGV[2])
        end
        local ttl = redis.call('TTL', KEYS[1])
        if current > tonumber(ARGV[1]) then
            return {0, current, ttl}
        end
        return {1, current, ttl}
    LUA;

    public function __construct(private readonly string $prefix = 'xpost:rl') {}

    /**
     * Try to reserve one publish slot for this account on this app.
     * Returns null when allowed, or the number of seconds to wait when not.
     */
    /**
     * Reserve the account's own daily allowance.
     *
     * Split from the app reservation so the caller can hold one account slot
     * while trying several apps -- an account stuck on an exhausted app should
     * borrow a sibling, not wait for midnight.
     */
    public function reserveAccountSlot(XAccount $account): ?int
    {
        return $this->consume(
            $this->key('account', $account->id, 'day'),
            $account->daily_post_cap ?: 50,
            86400,
        );
    }

    public function releaseAccountSlot(XAccount $account): void
    {
        $this->refund($this->key('account', $account->id, 'day'));
    }

    /**
     * Hand a reserved app slot back, both windows.
     *
     * For the case where the publish is abandoned after the reservation but
     * before the call to X -- nothing was spent at X, so nothing should be
     * spent here either. A slot burned on a post that never went out is a slot
     * some other account needed today.
     */
    public function releaseAppSlot(XApp $app): void
    {
        $this->refund($this->key('app', $app->id, 'burst'));
        $this->refund($this->key('app', $app->id, 'day'));
    }

    /**
     * Reserve one publish slot on this app: short-window smoothing first, then
     * the 24h allowance. A burst slot taken but not usable is handed straight
     * back, so probing a second app costs the first one nothing.
     *
     * @return int|null seconds to wait, or null when the app can take the post
     */
    public function reserveAppSlot(XApp $app, int $burstPerFifteenMinutes = 300): ?int
    {
        $burstWait = $this->consume(
            $this->key('app', $app->id, 'burst'),
            $burstPerFifteenMinutes,
            900,
        );

        if ($burstWait !== null) {
            return $burstWait;
        }

        $dayWait = $this->consume(
            $this->key('app', $app->id, 'day'),
            $app->daily_post_cap ?: 10_000,
            86400,
        );

        if ($dayWait !== null) {
            $this->refund($this->key('app', $app->id, 'burst'));

            return $dayWait;
        }

        return null;
    }

    /** @return int|null seconds to wait, or null when the call is allowed */
    private function consume(string $key, int $limit, int $windowSeconds): ?int
    {
        [$allowed, , $ttl] = Redis::eval(self::CONSUME, 1, $key, $limit, $windowSeconds);

        return (int) $allowed === 1 ? null : max(1, (int) $ttl);
    }

    private function refund(string $key): void
    {
        Redis::decr($key);
    }

    /**
     * Trust X over our own estimate: when a response says the window is spent,
     * park the app until the advertised reset.
     */
    public function syncFromResponse(XApp $app, RateLimitSnapshot $snapshot): void
    {
        if (! $snapshot->isExhausted() || $snapshot->resetAt === null) {
            return;
        }

        $seconds = max(1, (int) now()->diffInSeconds($snapshot->resetAt, false));

        Redis::setex($this->key('app', $app->id, 'blocked'), $seconds, 1);
    }

    public function appIsBlocked(XApp $app): bool
    {
        return (bool) Redis::exists($this->key('app', $app->id, 'blocked'));
    }

    public function appBlockedFor(XApp $app): int
    {
        return max(0, (int) Redis::ttl($this->key('app', $app->id, 'blocked')));
    }

    public function remainingForAccount(XAccount $account): int
    {
        $used = (int) Redis::get($this->key('account', $account->id, 'day'));

        return max(0, ($account->daily_post_cap ?: 50) - $used);
    }

    private function key(string $scope, int $id, string $window): string
    {
        $bucket = $window === 'day' ? now()->format('Ymd') : (string) intdiv(time(), 900);

        return "{$this->prefix}:{$scope}:{$id}:{$window}:{$bucket}";
    }
}
