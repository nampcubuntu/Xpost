<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\PostScheduleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A recurring plan: "this creative, at these times, on these days".
 *
 * Occurrences are computed rather than materialised. A year of three-a-day
 * slots is a thousand rows nobody reads, and any edit to the times would leave
 * the stale ones behind.
 */
class PostSchedule extends Model
{
    /** @use HasFactory<PostScheduleFactory> */
    use BelongsToTeam, HasFactory, SoftDeletes;

    protected $fillable = [
        'team_id', 'campaign_id', 'created_by', 'name', 'template',
        'times', 'days_of_week', 'timezone', 'starts_on', 'ends_on',
        'is_active', 'catch_up_minutes',
    ];

    protected function casts(): array
    {
        return [
            'template' => 'array',
            'times' => 'array',
            'days_of_week' => 'array',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
            'catch_up_minutes' => 'integer',
            'runs_count' => 'integer',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now());
    }

    /**
     * The next occurrence strictly after $after, in the schedule's own timezone.
     *
     * Walks forward a day at a time rather than doing modular arithmetic: with
     * at most seven days to check, clarity is worth more than cleverness, and
     * DST transitions fall out correctly because each candidate is built from
     * local wall-clock components.
     */
    public function nextOccurrenceAfter(?CarbonInterface $after = null): ?CarbonInterface
    {
        $times = $this->sortedTimes();

        if ($times === [] || ! $this->is_active) {
            return null;
        }

        $tz = $this->timezone ?: config('app.timezone');
        $cursor = CarbonImmutable::parse($after ?? now())->setTimezone($tz);

        /*
         * Jump to the start date rather than walking to it.
         *
         * The search below looks eight days ahead, which is all a weekly
         * pattern can need -- but a campaign told to begin in three weeks then
         * matched no day at all and computed no next run. scopeDue reads a null
         * next_run_at as "never", and nothing recomputes it later, so the
         * schedule was saved, listed, shown as active, and silently dead.
         */
        if ($this->starts_on) {
            $firstDay = CarbonImmutable::parse($this->starts_on)->setTimezone($tz)->startOfDay();

            // A second before it, so the first slot on that very day still
            // counts as "strictly after the cursor".
            $cursor = $cursor->lt($firstDay) ? $firstDay->subSecond() : $cursor;
        }

        $lastDay = $this->ends_on
            ? CarbonImmutable::parse($this->ends_on)->setTimezone($tz)->endOfDay()
            : null;

        for ($dayOffset = 0; $dayOffset <= 7; $dayOffset++) {
            $day = $cursor->addDays($dayOffset)->startOfDay();

            if ($this->starts_on && $day->lt(CarbonImmutable::parse($this->starts_on)->setTimezone($tz)->startOfDay())) {
                continue;
            }

            if ($lastDay && $day->gt($lastDay)) {
                return null;
            }

            if (! $this->runsOn($day)) {
                continue;
            }

            foreach ($times as $time) {
                [$hour, $minute] = array_map('intval', explode(':', $time));
                $candidate = $day->setTime($hour, $minute);

                if ($candidate->greaterThan($cursor)) {
                    return $candidate->setTimezone(config('app.timezone'));
                }
            }
        }

        return null;
    }

    /** Recompute and store the next firing time. */
    public function refreshNextRun(?CarbonInterface $after = null): void
    {
        $this->forceFill(['next_run_at' => $this->nextOccurrenceAfter($after)])->save();
    }

    /** ISO weekday, 1 = Monday. An empty list means every day. */
    public function runsOn(CarbonInterface $day): bool
    {
        $days = $this->days_of_week;

        return blank($days) || in_array((int) $day->isoWeekday(), array_map('intval', $days), true);
    }

    /** How many posts one day of this schedule produces per account. */
    public function postsPerDay(): int
    {
        return count($this->sortedTimes());
    }

    /**
     * @return list<string> HH:MM, ascending, de-duplicated
     *
     * The hour and minute have to be real ones. The old pattern accepted any
     * two numbers, so "25:00" and "99:99" were stored, counted towards the cost
     * estimate, and handed to setTime() -- which rolls them over into whatever
     * day and hour they land on. A slot nobody chose, at an hour nobody picked.
     */
    private function sortedTimes(): array
    {
        return collect($this->times ?? [])
            ->filter(fn ($t) => is_string($t) && preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $t))
            ->map(fn (string $t) => sprintf('%02d:%02d', ...array_map('intval', explode(':', $t))))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
