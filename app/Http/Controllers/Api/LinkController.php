<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\XApiException;
use App\Http\Controllers\Controller;
use App\Models\LinkDomain;
use App\Models\ShortLink;
use App\Models\Team;
use App\Services\Links\CloudflareKvClient;
use App\Services\Links\TinyUrlService;
use App\Support\TeamContext;
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

    public function __construct(
        private readonly CloudflareKvClient $kv,
        private readonly TinyUrlService $tiny,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('links.view');

        if (! $this->kv->isConfigured()) {
            return $this->notConfigured();
        }

        $request->validate([
            'prefix' => ['nullable', 'string', 'max:512'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = min(max((int) $request->query('per_page', 50), 1), 100);
        $page = max((int) $request->query('page', 1), 1);

        /*
         * Ordered here, not at Cloudflare.
         *
         * KV lists lexicographically and records nothing about when a key was
         * written, so newest-first was built by reading every key and sorting
         * the lot. That is one 642ms request per thousand keys -- fine at
         * eighty, and at three thousand links a day it is a minute of round
         * trips per page load inside a week, past a ceiling where the ordering
         * silently stops being true.
         *
         * short_links holds the key and its age and nothing else. The value is
         * still read from KV, for this page only.
         */
        $indexed = ShortLink::query()
            ->keyPrefix($request->query('prefix'))
            ->newestFirst()
            ->paginate($perPage, ['key', 'created_at'], 'page', $page);

        $names = $indexed->pluck('key')->all();

        /*
         * KV lists keys without their values, so the page needs a read per row.
         * One at a time that was a round trip each -- 531ms measured from here
         * to Cloudflare, so an eighty-key page spent three-quarters of a minute
         * before the table appeared. The bulk endpoint answers the whole page
         * in one.
         */
        try {
            $values = $names === [] ? [] : $this->kv->getMany($names);
        } catch (XApiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $rows = $indexed->map(fn (ShortLink $link) => [
            'key' => $link->key,
            /*
             * Absent rather than null-checked. A key deleted from Cloudflare by
             * someone else leaves a row here whose value comes back empty --
             * the same empty cell a key removed between the list and the read
             * has always produced.
             */
            'value' => $values[$link->key] ?? null,
            'created_at' => $link->created_at?->toIso8601String(),
        ])->all();
        $domains = $this->domainList();

        return response()->json([
            'data' => $this->withTinyLinks($rows, $domains),
            'meta' => [
                'current_page' => $indexed->currentPage(),
                'last_page' => $indexed->lastPage(),
                'per_page' => $indexed->perPage(),
                'total' => $indexed->total(),
            ],
            'domains' => $domains,
            'tinyurl_enabled' => $this->tiny->isEnabled(),
        ]);
    }

    /**
     * The hosts a code can be served from.
     *
     * Any of them resolves any code: they route to the same Worker over the
     * same namespace, so the domain is a choice made when a link is used
     * rather than something stored against the key.
     */
    public function domains(Request $request): JsonResponse
    {
        $this->authorize('links.view');

        return response()->json([
            'data' => $this->domainList(),
            'namespace_id' => Team::query()
                ->whereKey($request->user()->current_team_id)
                ->value('cloudflare_kv_namespace_id'),
        ]);
    }

    /**
     * Point this team at its own KV namespace.
     *
     * Not validated against Cloudflare here: a namespace the token cannot reach
     * fails on the next listing with Cloudflare's own message, which says more
     * than anything this could guess -- and refusing to save an id somebody is
     * still setting up would be worse than storing it.
     */
    public function updateNamespace(Request $request): JsonResponse
    {
        $this->authorize('links.manage');

        $data = $request->validate([
            'namespace_id' => ['present', 'nullable', 'string', 'max:64', 'regex:/^[0-9a-f]{32}$/'],
        ]);

        Team::query()
            ->whereKey($request->user()->current_team_id)
            ->update(['cloudflare_kv_namespace_id' => $data['namespace_id'] ?: null]);

        return response()->json(['namespace_id' => $data['namespace_id'] ?: null]);
    }

    /**
     * Attach the TinyURL already known for each row.
     *
     * Looked up, never minted: listing a page is a read, and opening it should
     * not create codes for every row it happens to show.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $domains
     * @return array<int, array<string, mixed>>
     */
    private function withTinyLinks(array $rows, array $domains): array
    {
        $default = collect($domains)->firstWhere('is_default', true) ?? collect($domains)->first();

        if ($default === null || ! $this->tiny->isEnabled()) {
            return array_map(fn (array $row) => $row + ['tiny_url' => null], $rows);
        }

        $targets = [];

        foreach ($rows as $row) {
            $targets[$row['key']] = 'https://'.$default['host'].'/'.LinkDomain::pathFor($row['key']);
        }

        $known = $this->tiny->known(array_values($targets));

        return array_map(
            fn (array $row) => $row + ['tiny_url' => $known[$targets[$row['key']]] ?? null],
            $rows,
        );
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
        $this->authorize('links.manage');

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
        $this->authorize('links.manage');

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
        $this->authorize('links.manage');

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
        $this->authorize('links.manage');

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
        $prefix = $data['prefix'] ?? 'r:';
        $key = $this->normaliseKey($data['key'] ?? null, $prefix);
        $value = $this->normaliseValue($data['value']);

        try {
            $this->kv->put($key, $value);

            // Indexed after the write, never before: a row for a key that
            // failed to land would be a link the page offers and the Worker
            // cannot resolve.
            ShortLink::remember((int) TeamContext::id(), $key);
        } catch (XApiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        /*
         * Shortened after the write, and best-effort: TinyURL being down must
         * not undo an entry that was saved correctly. The row simply comes back
         * without a tiny link, and the button on the page mints it later.
         */
        $tiny = $this->tinyFor($key);

        return response()->json([
            'data' => [
                'key' => $key,
                'value' => $value,
                'tiny_url' => $tiny,
            ],
            // Why there is no tiny link, when there is none. Without it the
            // row showed a Rút gọn button that had already failed and would
            // fail again on every press.
            'tiny_error' => $tiny === null ? $this->tiny->lastError() : null,
        ], 201);
    }

    /** The TinyURL for a key's short link, if shortening is switched on. */
    private function tinyFor(string $key): ?string
    {
        if (! $this->tiny->isEnabled()) {
            return null;
        }

        $domain = LinkDomain::query()->where('is_active', true)->orderByDesc('is_default')->first();

        return $domain === null ? null : $this->tiny->shorten($domain->url($key));
    }

    /**
     * Shorten the links on a page that do not have one yet.
     *
     * Separate from listing because it writes: opening a page should not mint
     * codes for every row it happens to show.
     */
    public function shorten(Request $request): JsonResponse
    {
        $this->authorize('links.manage');

        if (! $this->tiny->isEnabled()) {
            return response()->json(['message' => 'Chưa bật TinyURL. Đặt TINYURL_ENABLED=true trong .env.'], 422);
        }

        $data = $request->validate([
            'keys' => ['required', 'array', 'min:1', 'max:200'],
            'keys.*' => ['string', 'max:512'],
        ]);

        $domain = LinkDomain::query()->where('is_active', true)->orderByDesc('is_default')->first();

        if ($domain === null) {
            return response()->json(['message' => 'Chưa có domain nào để dựng short link.'], 422);
        }

        /*
         * All of them at once, rather than one after another.
         *
         * Measured at 315ms per call against the live endpoint, so shortening a
         * page of fifty held this request for sixteen seconds -- and `artisan
         * serve` handles one request at a time, which made that sixteen seconds
         * of the whole application.
         */
        $urls = array_map(fn (string $key) => $domain->url($key), $data['keys']);
        $tiny = $this->tiny->shortenMany($urls);

        $result = [];

        foreach ($data['keys'] as $key) {
            $result[$key] = $tiny[$domain->url($key)] ?? null;
        }

        return response()->json([
            'data' => $result,
            'tiny_error' => in_array(null, $result, true) ? $this->tiny->lastError() : null,
        ]);
    }

    /**
     * The key as KV stores it.
     *
     * Entries are prefixed so one namespace can hold more than one kind, but
     * nobody types the prefix -- and a key saved without it is invisible to
     * every prefix filter, which is how a namespace ends up with entries
     * nobody can find again.
     */
    private function normaliseKey(?string $typed, string $prefix): string
    {
        $typed = trim((string) $typed);

        if ($typed === '') {
            return $prefix.$this->code();
        }

        return str_contains($typed, ':') ? $typed : $prefix.$typed;
    }

    /**
     * The value as the Worker expects it: `<media>|<destination>`.
     *
     * One URL on its own gets the default domain appended, because that is
     * what every existing entry pairs with and typing it a hundred times is
     * how a typo gets in.
     */
    private function normaliseValue(string $value): string
    {
        $value = trim($value);

        if (str_contains($value, '|')) {
            return $value;
        }

        $host = LinkDomain::query()->where('is_active', true)->orderByDesc('is_default')->value('host');

        return $host === null ? $value : $value.'|https://'.$host.'/';
    }

    /**
     * Many pairs in one request.
     *
     * The reason this screen exists: Cloudflare's bulk endpoint takes thousands
     * at a time, and the dashboard takes them one click at a time.
     */
    public function bulk(Request $request): JsonResponse
    {
        $this->authorize('links.manage');

        if (! $this->kv->isConfigured()) {
            return $this->notConfigured();
        }

        $data = $request->validate([
            'pairs' => ['required', 'array', 'min:1', 'max:'.CloudflareKvClient::BULK_LIMIT],
            'pairs.*.key' => ['required', 'string', 'max:512'],
            'pairs.*.value' => ['required', 'string', 'max:25000'],
        ]);

        // The same shaping as a single entry: a bulk paste is exactly where a
        // hundred keys would otherwise land without their prefix.
        $pairs = [];

        foreach ($data['pairs'] as $pair) {
            $pairs[$this->normaliseKey($pair['key'], 'r:')] = $this->normaliseValue($pair['value']);
        }

        try {
            $written = $this->kv->putMany($pairs);

            /*
             * The whole paste in one statement rather than a row at a time:
             * this endpoint takes a thousand pairs, and a thousand inserts
             * is a thousand round trips to record what one already has.
             */
            $now = now();
            $teamId = (int) TeamContext::id();

            ShortLink::query()->upsert(
                array_map(
                    fn (string $key) => ['team_id' => $teamId, 'key' => $key, 'created_at' => $now, 'updated_at' => $now],
                    array_keys($pairs),
                ),
                ['team_id', 'key'],
                // Only the timestamp is touched on a repeat, and the age is
                // left alone: rewriting a value does not make a link new.
                ['updated_at'],
            );

            return response()->json(['written' => $written]);
        } catch (XApiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->authorize('links.manage');

        if (! $this->kv->isConfigured()) {
            return $this->notConfigured();
        }

        $data = $request->validate(['key' => ['required', 'string', 'max:512']]);

        try {
            $this->kv->delete($data['key']);

            ShortLink::query()
                ->where('key', $data['key'])
                ->delete();
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

    /**
     * Which half is missing decides who fixes it.
     *
     * The account and token are the operator's, set once in .env. The namespace
     * is this team's, and a team without one has no short-link store at all --
     * telling them to edit .env would send them somewhere they cannot go.
     */
    private function notConfigured(): JsonResponse
    {
        $operatorReady = filled(config('xpost.links.account_id'))
            && filled(config('xpost.links.api_token'));

        return response()->json([
            'message' => $operatorReady
                ? 'Team này chưa có KV namespace riêng. Nhập Namespace ID ở phần Domain.'
                : 'Chưa cấu hình Cloudflare. Điền CLOUDFLARE_ACCOUNT_ID và CLOUDFLARE_API_TOKEN trong .env.',
            'data' => [],
            'configured' => false,
            // So the page can offer the field instead of quoting an env file.
            'needs_namespace' => $operatorReady,
        ], 422);
    }
}
