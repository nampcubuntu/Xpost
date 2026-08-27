<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use Illuminate\Support\Facades\Cache;

/**
 * A team-wide stop, for a condition no individual post can recover from.
 *
 * X posting is pay-per-use with no free tier, so a spent balance refuses every
 * account at once. Without this, the first target hits the wall and the other
 * nine thousand nine hundred and ninety-nine follow it one at a time -- each
 * burning a rate-limit slot, a claim and a log line, on a call that cannot
 * succeed until a human tops up. At three in the morning nobody is watching.
 *
 * Held in the cache rather than a column so it expires on its own: an outage
 * that fixes itself should not need a second human to clear a flag. The TTL is
 * long enough to stop a night's queue and short enough that a top-up is picked
 * up without anyone touching Xpost.
 */
final class PublishingHalt
{
    private const TTL_MINUTES = 30;

    public function __construct(private readonly string $prefix = 'xpost:halt') {}

    public function engage(int $teamId, string $reason): void
    {
        try {
            Cache::put($this->key($teamId), [
                'reason' => $reason,
                'at' => now()->toIso8601String(),
            ], now()->addMinutes(self::TTL_MINUTES));
        } catch (\Throwable) {
            // A dead cache must not also stop the publish it was called from.
        }
    }

    /** @return array{reason: string, at: string}|null */
    public function current(int $teamId): ?array
    {
        try {
            return Cache::get($this->key($teamId));
        } catch (\Throwable) {
            return null;
        }
    }

    public function isEngaged(int $teamId): bool
    {
        return $this->current($teamId) !== null;
    }

    /** Clearing is a human saying the money is back; nothing does it silently. */
    public function release(int $teamId): void
    {
        try {
            Cache::forget($this->key($teamId));
        } catch (\Throwable) {
            // Nothing to do; it expires on its own.
        }
    }

    private function key(int $teamId): string
    {
        return $this->prefix.':'.$teamId;
    }
}
