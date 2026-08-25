<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Enums\ScheduleMode;
use App\Models\Post;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Decides when each individual target fires.
 *
 * Firing 10k targets on the same second is both a thundering herd against our
 * own workers and an obvious footprint on X's side, so every mode except
 * FixedTime deliberately spreads the load.
 */
final class SchedulePlanner
{
    /**
     * @param  int  $index  position of this target within the blast (0-based)
     * @param  int  $total  size of the blast, used to size the drip interval
     * @param  string|null  $accountTimezone  only consulted by PerAccountTimezone
     */
    public function slotFor(Post $post, int $index, int $total, ?string $accountTimezone = null): CarbonInterface
    {
        $base = CarbonImmutable::parse($post->scheduled_at ?? now())->setTimezone(config('app.timezone'));

        return match ($post->schedule_mode) {
            ScheduleMode::Immediate => $this->smoothed(CarbonImmutable::now(), $post, $index),
            ScheduleMode::FixedTime => $base,
            ScheduleMode::Drip => $this->drip($post, $base, $index, $total),
            ScheduleMode::Jitter => $this->jitter($post, $base),
            ScheduleMode::PerAccountTimezone => $this->localised($post, $base, $accountTimezone),
        };
    }

    /**
     * Even spread across the window. With no window we fall back to the
     * per-minute cap so throughput is still bounded.
     */
    private function drip(Post $post, CarbonImmutable $start, int $index, int $total): CarbonInterface
    {
        $end = $post->window_ends_at
            ? CarbonImmutable::parse($post->window_ends_at)
            : $start->addSeconds($this->impliedWindowSeconds($post, $total));

        $span = max(1, (int) $start->diffInSeconds($end, false));
        $step = $total > 1 ? $span / ($total - 1) : 0;

        return $start->addSeconds((int) round($step * $index));
    }

    /** Uniform random offset inside the window -- no detectable cadence. */
    private function jitter(Post $post, CarbonImmutable $start): CarbonInterface
    {
        $window = $post->window_ends_at
            ? max(1, (int) $start->diffInSeconds(CarbonImmutable::parse($post->window_ends_at), false))
            : max(1, $post->jitter_seconds ?: 3600);

        return $start->addSeconds(random_int(0, $window));
    }

    /**
     * Same local wall-clock time for every account. Accounts with no timezone
     * on file fall back to the post's own timezone.
     *
     * The hour is read back in the post's timezone, not the app's. `scheduled_at`
     * is stored in UTC, so a 09:00 Asia/Ho_Chi_Minh post is 02:00 on disk --
     * taking the hour off that sent every account in the world a post at two in
     * the morning, the Vietnamese ones included.
     */
    private function localised(Post $post, CarbonImmutable $base, ?string $accountTimezone): CarbonInterface
    {
        $postTz = $post->timezone ?: config('app.timezone');
        $intended = $base->setTimezone($postTz);

        $tz = $accountTimezone ?: $postTz;

        $local = CarbonImmutable::create(
            $intended->year,
            $intended->month,
            $intended->day,
            $intended->hour,
            $intended->minute,
            0,
            $tz,
        );

        // If that local instant has already passed today, roll to tomorrow.
        if ($local->isPast()) {
            $local = $local->addDay();
        }

        return $local->setTimezone(config('app.timezone'));
    }

    /** Immediate still respects per_minute_cap so a blast does not spike. */
    private function smoothed(CarbonImmutable $now, Post $post, int $index): CarbonInterface
    {
        $perMinute = $post->per_minute_cap;

        if ($perMinute === null || $perMinute <= 0) {
            return $now;
        }

        return $now->addSeconds((int) floor($index / $perMinute) * 60);
    }

    private function impliedWindowSeconds(Post $post, int $total): int
    {
        $perMinute = $post->per_minute_cap ?: 60;

        return (int) ceil($total / max(1, $perMinute)) * 60;
    }

    /**
     * Human-readable estimate for the composer: "10.000 bài, xong lúc 18:42".
     *
     * @return array{first: CarbonInterface, last: CarbonInterface, duration_seconds: int}
     */
    public function summarise(Post $post, int $total): array
    {
        $first = $this->slotFor($post, 0, $total);
        $last = $total > 1 ? $this->slotFor($post, $total - 1, $total) : $first;

        if ($last->lessThan($first)) {
            [$first, $last] = [$last, $first];
        }

        return [
            'first' => $first,
            'last' => $last,
            'duration_seconds' => (int) $first->diffInSeconds($last, false),
        ];
    }
}
