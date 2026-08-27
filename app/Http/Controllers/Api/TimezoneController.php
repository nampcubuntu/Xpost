<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\XAccount;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The full IANA timezone list for a picker.
 *
 * An X account pool is global: hard-coding a handful of Asian zones in the UI
 * meant an account in São Paulo could only be scheduled against Hanoi's clock.
 *
 * Ordered by offset rather than alphabetically by region, which is how X Ads
 * Manager orders its own and, more to the point, how the question is actually
 * asked. Nobody wants "the zones beginning with P"; they want UTC+7, and under
 * an alphabetical ordering the zones an hour apart are four hundred rows apart.
 */
class TimezoneController extends Controller
{
    public function index(): JsonResponse
    {
        // The list only changes when the tzdata package does, and the offsets
        // only when a zone crosses a DST boundary -- an hour is plenty.
        $zones = Cache::remember('timezones:flat', now()->addHour(), function (): array {
            $zones = [];

            foreach (DateTimeZone::listIdentifiers() as $identifier) {
                $minutes = $this->offsetMinutes($identifier);

                $zones[] = [
                    'value' => $identifier,
                    // The whole identifier, not just the city. "Pacific/Niue"
                    // and "Asia/Ho Chi Minh" both need their region to be read
                    // as places rather than as words.
                    'label' => str_replace('_', ' ', $identifier),
                    'offset' => $this->offsetLabel($minutes),
                    // Kept separately so the client sorts and filters on the
                    // number rather than parsing the label back apart.
                    'offset_minutes' => $minutes,
                ];
            }

            usort($zones, fn (array $a, array $b) => [$a['offset_minutes'], $a['label']]
                <=> [$b['offset_minutes'], $b['label']]);

            return $zones;
        });

        return response()->json([
            'data' => $zones,
            // What the pool actually uses, so the picker can put the zones this
            // team already works in at the top instead of 400 sorted ones.
            'in_use' => $this->inUse(),
        ]);
    }

    /** Timezones the team's accounts are actually in, most common first. */
    private function inUse(): array
    {
        return XAccount::query()
            ->whereNotNull('timezone')
            ->selectRaw('timezone, COUNT(*) as accounts')
            ->groupBy('timezone')
            ->orderByDesc('accounts')
            ->limit(20)
            ->get()
            ->map(fn ($row) => [
                'value' => $row->timezone,
                'label' => str_replace('_', ' ', $row->timezone),
                'offset' => $this->offsetLabel($this->offsetMinutes($row->timezone)),
                'accounts' => (int) $row->accounts,
            ])
            ->all();
    }

    /** Minutes east of UTC as of now, so it stays right through DST changes. */
    private function offsetMinutes(string $identifier): int
    {
        return intdiv(Carbon::now(new DateTimeZone($identifier))->getOffset(), 60);
    }

    /**
     * "UTC+7", "UTC-9:30", "UTC" — the shape X uses.
     *
     * No padding and no minutes when there are none: a column of UTC+07:00
     * reads as a timestamp, and the half-hour zones are the only ones where
     * the minutes carry information.
     */
    private function offsetLabel(int $minutes): string
    {
        if ($minutes === 0) {
            return 'UTC';
        }

        $sign = $minutes < 0 ? '-' : '+';
        $minutes = abs($minutes);
        $rest = $minutes % 60;

        return 'UTC'.$sign.intdiv($minutes, 60).($rest === 0 ? '' : ':'.sprintf('%02d', $rest));
    }
}
