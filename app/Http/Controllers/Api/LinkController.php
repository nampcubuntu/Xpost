<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\XApiException;
use App\Http\Controllers\Controller;
use App\Models\LinkDomain;
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
            'domains' => $this->domainList(),
        ]);
    }

    /**
     * The hosts a code can be served from.
     *
     * Any of them resolves any code: they route to the same Worker over the
     * same namespace, so the domain is a choice made when a link is used
     * rather than something stored against the key.
     */
    public function domains(): JsonResponse
    {
        return response()->json(['data' => $this->domainList()]);
    }

    /** @return array<int, array<string, mixed>> */
    private function domainList(): array
    {
        /*
         * Seed the first row from the old single-value setting, so an install
         * that already had one keeps working without anyone re-entering it.
         */
        if (! LinkDomain::query()->exists() && filled($seed = config('xpost.links.base_url'))) {
            LinkDomain::create([
                'host' => LinkDomain::normaliseHost($seed),
                'label' => 'từ XPOST_LINK_BASE_URL',
                'is_default' => true,
            ]);
        }

        return LinkDomain::query()
            ->orderByDesc('is_default')
            ->orderBy('host')
            ->get(['id', 'host', 'label', 'is_default', 'is_active'])
            ->toArray();
    }

    public function storeDomain(Request $request): JsonResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:253'],
            'label' => ['nullable', 'string', 'max:100'],
            'is_default' => ['boolean'],
        ]);

        $host = LinkDomain::normaliseHost($data['host']);

        if ($host === '' || ! str_contains($host, '.')) {
            return response()->json(['message' => 'Domain không hợp lệ.'], 422);
        }

        if (LinkDomain::query()->where('host', $host)->exists()) {
            return response()->json(['message' => "Domain {$host} đã có."], 422);
        }

        // The first one is the default whether or not it was asked for --
        // otherwise the list has entries and nothing to build a URL from.
        $isDefault = ($data['is_default'] ?? false) || ! LinkDomain::query()->exists();

        $domain = LinkDomain::create([
            'host' => $host,
            'label' => $data['label'] ?? null,
            'is_default' => $isDefault,
        ]);

        if ($isDefault) {
            LinkDomain::query()->whereKeyNot($domain->id)->update(['is_default' => false]);
        }

        return response()->json(['data' => $domain], 201);
    }

    public function updateDomain(Request $request, LinkDomain $domain): JsonResponse
    {
        $data = $request->validate([
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        $domain->fill($data)->save();

        if ($data['is_default'] ?? false) {
            LinkDomain::query()->whereKeyNot($domain->id)->update(['is_default' => false]);
        }

        return response()->json(['data' => $domain->fresh()]);
    }

    public function destroyDomain(LinkDomain $domain): JsonResponse
    {
        $domain->delete();

        // Never leave the list without a default: every short URL is built
        // from one, and a list with none silently stops producing links.
        if ($domain->is_default && ($next = LinkDomain::query()->orderBy('host')->first())) {
            $next->forceFill(['is_default' => true])->save();
        }

        return response()->json(status: 204);
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
