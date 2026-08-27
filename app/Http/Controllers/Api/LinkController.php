<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\XApiException;
use App\Http\Controllers\Controller;
use App\Services\Links\CloudflareKvClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The short-link store, as an ordinary CRUD screen.
 *
 * It is the same Cloudflare KV namespace a Worker reads; the point of putting
 * it here is that the dashboard has no bulk entry, no search beyond a prefix,
 * and no way to add a hundred pairs without a hundred clicks.
 *
 * Nothing is cached locally. A stale mirror of someone else's store is worse
 * than a slightly slower page: it would show keys that no longer resolve, and
 * hide ones written from anywhere else.
 */
class LinkController extends Controller
{
    private const CODE_ALPHABET = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly CloudflareKvClient $kv) {}

    public function index(Request $request): JsonResponse
    {
        if (! $this->kv->isConfigured()) {
            return $this->notConfigured();
        }

        $request->validate([
            'prefix' => ['nullable', 'string', 'max:512'],
            'cursor' => ['nullable', 'string', 'max:4096'],
        ]);

        try {
            $page = $this->kv->listKeys(
                $request->query('prefix') ?: null,
                $request->query('cursor') ?: null,
            );
        } catch (XApiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        /*
         * KV lists keys without their values, so each row costs a read. A page
         * is a hundred keys, which is a hundred small requests -- slow enough to
         * notice and still far quicker than opening a hundred dashboard rows.
         */
        $rows = [];

        foreach ($page['keys'] as $key) {
            $rows[] = [
                'key' => $key['name'],
                'value' => $this->kv->get($key['name']),
            ];
        }

        return response()->json([
            'data' => $rows,
            'cursor' => $page['cursor'],
            'base_url' => config('xpost.links.base_url'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->kv->isConfigured()) {
            return $this->notConfigured();
        }

        $data = $request->validate([
            // Left blank, a code is generated: the key is a lookup handle, not
            // something anyone should have to invent one at a time.
            'key' => ['nullable', 'string', 'max:512'],
            'value' => ['required', 'string', 'max:25000'],
            'prefix' => ['nullable', 'string', 'max:32'],
        ]);

        // validate() returns only the keys that were sent, so a field left out
        // of the request is absent here rather than null.
        $key = ($data['key'] ?? null) ?: ($data['prefix'] ?? 'r:').$this->code();

        try {
            $this->kv->put($key, $data['value']);
        } catch (XApiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['key' => $key, 'value' => $data['value']]], 201);
    }

    /**
     * Many pairs in one request.
     *
     * The reason this screen exists: Cloudflare's bulk endpoint takes thousands
     * at a time, and the dashboard takes them one click at a time.
     */
    public function bulk(Request $request): JsonResponse
    {
        if (! $this->kv->isConfigured()) {
            return $this->notConfigured();
        }

        $data = $request->validate([
            'pairs' => ['required', 'array', 'min:1', 'max:'.CloudflareKvClient::BULK_LIMIT],
            'pairs.*.key' => ['required', 'string', 'max:512'],
            'pairs.*.value' => ['required', 'string', 'max:25000'],
        ]);

        $pairs = [];

        foreach ($data['pairs'] as $pair) {
            $pairs[$pair['key']] = $pair['value'];
        }

        try {
            return response()->json(['written' => $this->kv->putMany($pairs)]);
        } catch (XApiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroy(Request $request): JsonResponse
    {
        if (! $this->kv->isConfigured()) {
            return $this->notConfigured();
        }

        $data = $request->validate(['key' => ['required', 'string', 'max:512']]);

        try {
            $this->kv->delete($data['key']);
        } catch (XApiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(status: 204);
    }

    /**
     * A short code with no characters that are read wrong out loud or copied
     * wrong by eye: no 0/O, no 1/l/I.
     */
    private function code(int $length = 8): string
    {
        $alphabet = self::CODE_ALPHABET;
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    private function notConfigured(): JsonResponse
    {
        return response()->json([
            'message' => 'Chưa cấu hình Cloudflare KV. Điền CLOUDFLARE_ACCOUNT_ID, '
                .'CLOUDFLARE_KV_NAMESPACE_ID và CLOUDFLARE_API_TOKEN trong .env.',
            'data' => [],
            'configured' => false,
        ], 422);
    }
}
