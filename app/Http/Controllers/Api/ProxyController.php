<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ProxyScheme;
use App\Http\Controllers\Controller;
use App\Models\Proxy;
use App\Models\XAccount;
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
                    'health_status', 'latency_ms', 'assigned_count', 'max_accounts', 'last_checked_at']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        return response()->json(Proxy::create($this->validated($request)), 201);
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
            ])->save();

            return response()->json([
                'health_status' => $proxy->health_status,
                'latency_ms' => $latency,
                'status_code' => $response->status(),
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
