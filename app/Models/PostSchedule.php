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

    /** @return list<string> HH:MM, ascending, de-duplicated */
    private function sortedTimes(): array
    {
        $times = collect($this->times ?? [])
            ->filter(fn ($t) => is_string($t) && preg_match('/^\d{1,2}:\d{2}$/', $t))
            ->map(fn (string $t) => sprintf('%02d:%02d', ...array_map('intval', explode(':', $t))))
            ->unique()
            ->sort()
            ->values();

        return $times->all();
    }
}
