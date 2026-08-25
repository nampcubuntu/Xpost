<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * X API access tiers. The numbers below are planning defaults used to size the
 * token buckets; override per record with monthly_post_cap / daily_post_cap when
 * your contract differs.
 */
enum XAppTier: string
{
    case Free = 'free';
    case Basic = 'basic';
    case Pro = 'pro';
    case Enterprise = 'enterprise';

    public function defaultMonthlyPostCap(): int
    {
        return match ($this) {
            self::Free => 500,
            self::Basic => 3_000,
            self::Pro => 300_000,
            self::Enterprise => 10_000_000,
        };
    }

    /** POST /2/tweets calls allowed per 24h for the whole app. */
    /**
     * POST /2/tweets calls allowed per 24h for the whole app.
     *
     * X applies the same 10,000/24h rate limit to every app regardless of plan;
     * what the plan changes is the bill, not the throughput. Enterprise
     * contracts are negotiated, so they get a higher planning default.
     */
    public function defaultDailyPostCap(): int
    {
        return $this === self::Enterprise ? 300_000 : 10_000;
    }
}
