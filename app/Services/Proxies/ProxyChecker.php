<?php

declare(strict_types=1);

namespace App\Services\Proxies;

use App\Models\Proxy;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Is this proxy usable, and where does it come out?
 *
 * Lives outside the controller because the same check runs from two places: one
 * row on demand, and a queued job when a selection is checked. Checking a
 * hundred proxies inline would hold an HTTP request open for minutes -- each
 * one can spend fifteen seconds finding out it is dead.
 */
class ProxyChecker
{
    /**
     * @return array{health_status: string, latency_ms: int|null, status_code: int|null, error: string|null}
     */
    public function check(Proxy $proxy): array
    {
        $startedAt = hrtime(true);

        try {
            /*
             * Against X itself rather than a generic IP echo service: a proxy
             * that reaches the internet but is blocked by X is still useless,
             * and finding that out at publish time costs a post.
             */
            $response = Http::withOptions(['proxy' => $proxy->toUri()])
                ->timeout(15)
                ->get('https://api.x.com/2/openapi.json');

            $latency = (int) ((hrtime(true) - $startedAt) / 1_000_000);
            $healthy = $response->status() < 500;

            $proxy->forceFill([
                'health_status' => $healthy ? 'healthy' : 'degraded',
                'latency_ms' => $latency,
                'fail_count' => $healthy ? 0 : $proxy->fail_count + 1,
                'last_checked_at' => now(),
                'check_queued_at' => null,
            ] + ($healthy ? $this->geolocate($proxy) : []))->save();

            return [
                'health_status' => $proxy->health_status,
                'latency_ms' => $latency,
                'status_code' => $response->status(),
                'error' => null,
            ];
        } catch (Throwable $e) {
            $proxy->forceFill([
                'health_status' => 'dead',
                'fail_count' => $proxy->fail_count + 1,
                'last_checked_at' => now(),
                'check_queued_at' => null,
            ])->save();

            return [
                'health_status' => 'dead',
                'latency_ms' => null,
                'status_code' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Where the proxy comes out, asked through the proxy itself.
     *
     * The country column was typed by hand, which makes it a note rather than a
     * fact -- a seller's label and the exit IP disagree often enough that
     * assigning accounts by country off the label is guesswork. The check is
     * already talking to the internet from there, so it can ask.
     *
     * Failure is deliberately silent. A geo service being down says nothing
     * about whether the proxy works, and letting it downgrade a healthy proxy
     * would be the tail wagging the dog.
     *
     * @return array<string, mixed>
     */
    private function geolocate(Proxy $proxy): array
    {
        $endpoint = (string) config('xpost.proxies.geo_endpoint');

        if ($endpoint === '') {
            return [];
        }

        try {
            $body = Http::withOptions(['proxy' => $proxy->toUri()])
                ->timeout(10)
                ->get($endpoint)
                ->json();
        } catch (Throwable) {
            return [];
        }

        // ipwho.is answers 200 with success:false for a rate limit or a
        // reserved address, so the status code alone is not the verdict.
        if (! is_array($body) || ($body['success'] ?? true) === false) {
            return [];
        }

        /*
         * Read under several spellings so the endpoint can be swapped without
         * touching code: ipwho.is and ipapi.co say country_code and region,
         * ip-api.com says countryCode and regionName.
         */
        $country = $body['country_code'] ?? $body['countryCode'] ?? null;

        return array_filter([
            'exit_ip' => $body['ip'] ?? $body['query'] ?? null,
            'country' => is_string($country) ? strtoupper(substr($country, 0, 2)) : null,
            'region' => $body['region'] ?? $body['regionName'] ?? null,
            'city' => $body['city'] ?? null,
            'geo_checked_at' => now(),
        ], fn ($value) => filled($value));
    }
}
