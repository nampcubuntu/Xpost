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
 * The full IANA timezone list, grouped for a picker.
 *
 * An X account pool is global: hard-coding a handful of Asian zones in the UI
 * meant an account in São Paulo could only be scheduled against Hanoi's clock.
 */
class TimezoneController extends Controller
{
    public function index(): JsonResponse
    {
        // The list only changes when the tzdata package does, and the offsets
        // only when a zone crosses a DST boundary -- an hour is plenty.
        $groups = Cache::remember('timezones:grouped', now()->addHour(), function (): array {
            $groups = [];

            foreach (DateTimeZone::listIdentifiers() as $identifier) {
                // "Asia/Ho_Chi_Minh" -> region "Asia", city "Ho Chi Minh".
                [$region, $city] = array_pad(explode('/', $identifier, 2), 2, null);

                $groups[$region][] = [
                    'value' => $identifier,
                    'label' => str_replace('_', ' ', $city ?? $region),
                    'offset' => $this->offsetLabel($identifier),
                ];
            }

            ksort($groups);

            return collect($groups)
                ->map(fn (array $zones, string $region) => [
                    'region' => $region,
                    'zones' => collect($zones)->sortBy('label')->values()->all(),
                ])
                ->values()
                ->all();
        });

        return response()->json([
            'data' => $groups,
            // What the pool actually uses, so the picker can put the zones this
            // team already works in at the top instead of 400 alphabetised ones.
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
                'offset' => $this->offsetLabel($row->timezone),
                'accounts' => (int) $row->accounts,
            ])
            ->all();
    }

    /** "UTC+07:00" as of now, so the label is right through DST changes. */
    private function offsetLabel(string $identifier): string
    {
        $offset = Carbon::now(new DateTimeZone($identifier))->getOffset();
        $sign = $offset < 0 ? '-' : '+';
        $offset = abs($offset);

        return sprintf('UTC%s%02d:%02d', $sign, intdiv($offset, 3600), intdiv($offset % 3600, 60));
    }
}
