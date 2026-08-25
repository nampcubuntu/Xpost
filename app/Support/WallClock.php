<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Reads a browser's wall-clock string as the instant the user meant.
 *
 * `<input type="datetime-local">` sends "2026-08-25T20:00" with no offset. Put
 * straight into a UTC column it becomes 20:00 UTC -- 03:00 the next morning in
 * Vietnam, and a different wrong hour for every other country the account pool
 * lives in. The timezone the user picked is what disambiguates it.
 */
final class WallClock
{
    /**
     * Convert to the app timezone, interpreting a naive string in $timezone.
     *
     * A value that already carries an offset is unambiguous, so it is converted
     * rather than reinterpreted. Anything unparseable is handed back untouched
     * for the validator to report.
     */
    public static function toAppTimezone(mixed $value, ?string $timezone): mixed
    {
        if (! is_string($value) || trim($value) === '') {
            return $value;
        }

        $carriesOffset = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/', trim($value));

        try {
            return CarbonImmutable::parse($value, $carriesOffset ? null : ($timezone ?: config('app.timezone')))
                ->setTimezone(config('app.timezone'))
                ->toDateTimeString();
        } catch (\Throwable) {
            return $value;
        }
    }
}
