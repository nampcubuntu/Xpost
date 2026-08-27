<?php

declare(strict_types=1);

namespace App\Services\Links;

use App\Models\TinyLink;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shortening a short link again, through TinyURL.
 *
 * Two APIs, and which one is used depends on whether a token is configured:
 * the modern one takes a Bearer token and answers JSON; the legacy one needs
 * no account and answers a bare line of text. The legacy endpoint is the
 * fallback so the feature works before anyone has signed up for anything.
 *
 * Every result is remembered. Asking twice for the same URL mints a second
 * code, and a post already carrying the first becomes untraceable -- two live
 * links to the same place and nothing saying which is in use where.
 */
final class TinyUrlService
{
    private const MODERN = 'https://api.tinyurl.com/create';

    private const LEGACY = 'https://tinyurl.com/api-create.php';

    public function isEnabled(): bool
    {
        return (bool) config('xpost.links.tinyurl.enabled', false);
    }

    /** Whatever is already known for these targets, so nothing is re-minted. */
    public function known(array $targetUrls): array
    {
        if ($targetUrls === []) {
            return [];
        }

        return TinyLink::query()
            ->whereIn('target_hash', array_map(TinyLink::hashFor(...), $targetUrls))
            ->pluck('tiny_url', 'target_url')
            ->all();
    }

    /**
     * The TinyURL for one target, minting it only if there is not one already.
     *
     * Best-effort by design: this runs after the KV write, and TinyURL being
     * down must not undo an entry that was saved correctly. The caller gets
     * null and the row simply has no tiny link yet.
     */
    public function shorten(string $targetUrl, ?string $alias = null): ?string
    {
        $existing = TinyLink::query()
            ->where('target_hash', TinyLink::hashFor($targetUrl))
            ->value('tiny_url');

        if ($existing !== null) {
            return $existing;
        }

        try {
            $tiny = $this->mint($targetUrl, $alias);
        } catch (\Throwable $e) {
            Log::channel('xpost')->warning('TinyURL failed', [
                'target' => $targetUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($tiny === null) {
            return null;
        }

        TinyLink::create([
            'target_url' => $targetUrl,
            'target_hash' => TinyLink::hashFor($targetUrl),
            'tiny_url' => $tiny,
            'alias' => $alias,
        ]);

        return $tiny;
    }

    private function mint(string $targetUrl, ?string $alias): ?string
    {
        $token = (string) config('xpost.links.tinyurl.token');
        $timeout = (int) config('xpost.http.timeout', 30);

        if ($token === '') {
            /*
             * The legacy endpoint answers the URL as plain text, or the word
             * "Error" with a 200 -- so the body has to be inspected, not just
             * the status.
             */
            $response = Http::timeout($timeout)->get(self::LEGACY, ['url' => $targetUrl]);
            $body = trim($response->body());

            return $response->successful() && str_starts_with($body, 'http') ? $body : null;
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout($timeout)
            ->post(self::MODERN, array_filter([
                'url' => $targetUrl,
                'domain' => config('xpost.links.tinyurl.domain') ?: 'tinyurl.com',
                'alias' => $alias,
            ]));

        if ($response->failed()) {
            /*
             * A taken alias is the one failure worth retrying differently: the
             * link is still wanted, just not under that name.
             */
            return $alias === null ? null : $this->mint($targetUrl, null);
        }

        return $response->json('data.tiny_url');
    }
}
