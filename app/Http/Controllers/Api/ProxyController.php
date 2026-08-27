<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ProxyScheme;
use App\Http\Controllers\Controller;
use App\Models\Proxy;
use App\Models\XAccount;
use App\Support\ProxyLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class ProxyController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', XAccount::class);

        return response()->json([
            'data' => Proxy::query()
                ->orderBy('label')
                ->get(['id', 'label', 'scheme', 'host', 'port', 'country', 'is_active',
                    'health_status', 'latency_ms', 'assigned_count', 'max_accounts', 'last_checked_at',
                    'exit_ip', 'region', 'city']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        return response()->json(Proxy::create($this->validated($request)), 201);
    }

    /**
     * A supplier's whole delivery, pasted.
     *
     * Proxies arrive as a text blob and every seller picks a different column
     * order, so ProxyLine recognises each field by what it looks like instead
     * of by where it sits. Entering a thousand of them one form at a time is
     * not a workflow.
     *
     * A line already on the team is refreshed rather than refused: re-pasting
     * the supplier's list is how a rotated password gets applied, and "đã tồn
     * tại" on 900 of 1000 lines would make that impossible.
     */
    public function bulk(Request $request): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        $data = $request->validate([
            'text' => ['required', 'string', 'max:500000'],
            'scheme' => ['nullable', Rule::enum(ProxyScheme::class)],
            'max_accounts' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        [$lines, $skipped] = ProxyLine::parseMany($data['text']);
        $limit = (int) config('xpost.proxies.bulk_limit');

        if (count($lines) > $limit) {
            return response()->json([
                'message' => "Tối đa {$limit} proxy mỗi lần dán. Hãy chia nhỏ danh sách.",
            ], 422);
        }

        /*
         * Every existing row up front, rather than a SELECT per line: at two
         * thousand lines that is the difference between one query and two
         * thousand, and the rows are four small columns.
         */
        $existing = Proxy::query()
            ->get(['id', 'host', 'port', 'username'])
            ->keyBy(fn (Proxy $proxy) => self::fingerprint($proxy->host, $proxy->port, $proxy->username));

        $created = 0;
        $updated = 0;

        foreach ($lines as $line) {
            $attributes = [
                'scheme' => $line->scheme ?? $data['scheme'] ?? ProxyScheme::Http->value,
                'host' => $line->host,
                'port' => $line->port,
                'username' => $line->username,
                'password' => $line->password,
            ];

            $match = $existing->get(self::fingerprint($line->host, $line->port, $line->username));

            if ($match !== null) {
                $match->fill($attributes)->save();
                $updated++;

                continue;
            }

            $proxy = Proxy::create($attributes + array_filter([
                'max_accounts' => $data['max_accounts'] ?? null,
            ]));

            // Kept so a list that repeats a line updates it rather than
            // failing the unique index on the second pass.
            $existing->put(self::fingerprint($line->host, $line->port, $line->username), $proxy);
            $created++;
        }

        return response()->json([
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ], 201);
    }

    public function update(Request $request, Proxy $proxy): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        $data = $this->validated($request, $proxy);

        // A blank password field means "keep the stored one".
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $proxy->fill($data)->save();

        return response()->json($proxy->fresh());
    }

    public function destroy(Proxy $proxy): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        if ($proxy->assigned_count > 0) {
            return response()->json(['message' => __('xpost.errors.proxy_in_use')], 422);
        }

        $proxy->delete();

        return response()->json(status: 204);
    }

    /**
     * Live check against X itself rather than a generic IP echo service -- a
     * proxy that reaches the internet but is blocked by X is still useless.
     */
    public function check(Proxy $proxy): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        $startedAt = hrtime(true);

        try {
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
            ] + ($healthy ? $this->geolocate($proxy) : []))->save();

            return response()->json([
                'health_status' => $proxy->health_status,
                'latency_ms' => $latency,
                'status_code' => $response->status(),
                'exit_ip' => $proxy->exit_ip,
                'country' => $proxy->country,
                'region' => $proxy->region,
                'city' => $proxy->city,
            ]);
        } catch (\Throwable $e) {
            $proxy->forceFill([
                'health_status' => 'dead',
                'fail_count' => $proxy->fail_count + 1,
                'last_checked_at' => now(),
            ])->save();

            return response()->json(['health_status' => 'dead', 'error' => $e->getMessage()]);
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
     * @return array<string, string|null>
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
        } catch (\Throwable) {
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

    /** The unique index, as a string: host, port and username identify a row. */
    private static function fingerprint(string $host, int $port, ?string $username): string
    {
        return strtolower($host)."\0{$port}\0".($username ?? '');
    }

    private function validated(Request $request, ?Proxy $existing = null): array
    {
        return $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'scheme' => [$existing ? 'sometimes' : 'required', Rule::enum(ProxyScheme::class)],
            'host' => [$existing ? 'sometimes' : 'required', 'string', 'max:255'],
            'port' => [$existing ? 'sometimes' : 'required', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'size:2'],
            'max_accounts' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'is_active' => ['boolean'],
        ]);
    }
}
