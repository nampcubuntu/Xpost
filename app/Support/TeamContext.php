<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Context;

/**
 * The active tenant for the current request or job.
 *
 * Backed by Laravel's Context so the value is automatically carried into
 * dispatched jobs and stamped onto every log line -- which is what makes a
 * failed publish traceable back to a team without threading an ID through
 * every constructor.
 */
final class TeamContext
{
    private const KEY = 'team_id';

    public static function id(): ?int
    {
        $value = Context::get(self::KEY);

        return $value === null ? null : (int) $value;
    }

    public static function set(?int $teamId): void
    {
        $teamId === null
            ? Context::forget(self::KEY)
            : Context::add(self::KEY, $teamId);
    }

    /** Run a callback with the given tenant active, then restore the previous one. */
    public static function runAs(?int $teamId, callable $callback): mixed
    {
        $previous = self::id();
        self::set($teamId);

        try {
            return $callback();
        } finally {
            self::set($previous);
        }
    }

    /** Escape the tenant scope entirely -- for cross-team maintenance commands. */
    public static function runUnscoped(callable $callback): mixed
    {
        return self::runAs(null, $callback);
    }
}
