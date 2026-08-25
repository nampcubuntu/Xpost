<?php

declare(strict_types=1);

namespace App\Enums;

enum ScheduleMode: string
{
    /** Publish as fast as the queue and rate limits allow. */
    case Immediate = 'immediate';

    /** Every target fires at the same wall-clock instant. */
    case FixedTime = 'fixed_time';

    /** Targets spread evenly between starts_at and ends_at. */
    case Drip = 'drip';

    /** Each target gets a random offset inside the window, so the burst is not uniform. */
    case Jitter = 'jitter';

    /** Each account fires at the given local hour in its own timezone. */
    case PerAccountTimezone = 'per_account_timezone';

    public function needsWindow(): bool
    {
        return in_array($this, [self::Drip, self::Jitter], true);
    }
}
