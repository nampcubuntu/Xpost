<?php

declare(strict_types=1);

namespace App\Services\Links;

use App\Models\TinyLink;
use Illuminate\Http\Client\Response;
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

    /**
     * How many to have in flight at once.
     *
     * Measured against the modern endpoint: ten concurrent is 72ms a link
     * where five was 188ms -- 2.6x, with every one of them landing.
     */
    private const BATCH = 10;

    /**
     * Enabled means able to produce a link worth having.
     *
     * The token is part of that now. Without one the only endpoint available is
     * the anonymous one, and TinyURL has deprecated it: a link minted there
     * answers 302 to tinyurl.com/preview/deprecated/… -- an interstitial that
     * holds the visitor for ten seconds behind a "Continue to Your Destination"
     * button. A link that does that is worse than no link, and this was
     * producing them silently.
     *
     * With a token the modern endpoint answers 301 straight to the
     * destination, which is the whole point of shortening anything.
     */
    public function isEnabled(): bool
    {
        return (bool) config('xpost.links.tinyurl.enabled', false)
            && filled(config('xpost.links.tinyurl.token'));
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

    /**
     * A page of targets, shortened together.
     *
     * One at a time this is 315ms each, measured against the live endpoint --
     * so the button that shortens a page of fifty held the request for sixteen
     * seconds, and `artisan serve` is single-threaded, which makes that sixteen
     * seconds of the whole app.
     *
     * Concurrent, but in small batches rather than all at once: the legacy
     * endpoint needs no account and publishes no rate limit, which is exactly
     * why it should not be handed fifty simultaneous requests. Fifty targets
     * become ten rounds of five.
     *
     * Only the modern endpoint is pooled. The legacy one is a plain GET and
     * pools the same way; the difference is only in how the answer is read.
     *
     * @param  list<string>  $targetUrls
     * @return array<string, string|null> keyed by target, null where it failed
     */
    public function shortenMany(array $targetUrls): array
    {
        $targetUrls = array_values(array_unique($targetUrls));

        // Everything already minted, in one query rather than one per target.
        $known = $this->known($targetUrls);
        $missing = array_values(array_diff($targetUrls, array_keys($known)));

        if ($missing === []) {
            return $known;
        }

        $token = (string) config('xpost.links.tinyurl.token');
        $timeout = (int) config('xpost.http.timeout', 30);
        $minted = [];

        // Same rule as isEnabled(): without a token the only endpoint left
        // mints links that detour through an interstitial.
        if ($token === '') {
            return array_replace(array_fill_keys($targetUrls, null), $known);
        }

        foreach (array_chunk($missing, self::BATCH) as $chunk) {
            try {
                $responses = Http::pool(fn ($pool) => array_map(
                    fn (string $url) => $this->queue($pool, $url, $token, $timeout),
                    $chunk,
                ));
            } catch (\Throwable $e) {
                Log::channel('xpost')->warning('TinyURL batch failed', ['error' => $e->getMessage()]);

                continue;
            }

            foreach ($chunk as $i => $url) {
                $tiny = $this->read($responses[$i] ?? null);

                if ($tiny !== null) {
                    $minted[$url] = $tiny;
                }
            }
        }

        /*
         * Written after the whole run, and only what came back. A failure
         * leaves no row, so the next attempt tries again rather than caching
         * the absence.
         */
        foreach ($minted as $url => $tiny) {
            TinyLink::create([
                'target_url' => $url,
                'target_hash' => TinyLink::hashFor($url),
                'tiny_url' => $tiny,
            ]);
        }

        // Nulls for the ones that failed, so the caller can tell them apart
        // from ones it never asked about.
        return array_replace(
            array_fill_keys($targetUrls, null),
            $known,
            $minted,
        );
    }

    /** One request into the pool. */
    private function queue($pool, string $url, string $token, int $timeout)
    {
        return $pool->withToken($token)->acceptJson()->timeout($timeout)->post(self::MODERN, [
            'url' => $url,
            'domain' => config('xpost.links.tinyurl.domain') ?: 'tinyurl.com',
        ]);
    }

    /**
     * The tiny link out of one response, or null.
     *
     * A pooled entry is a Throwable when the request itself failed, which is
     * not a response and must not be called as one.
     */
    private function read($response): ?string
    {
        if (! $response instanceof Response) {
            return null;
        }

        return $response->failed() ? null : $response->json('data.tiny_url');
    }

    private function mint(string $targetUrl, ?string $alias): ?string
    {
        $token = (string) config('xpost.links.tinyurl.token');
        $timeout = (int) config('xpost.http.timeout', 30);

        // No token, no link. See isEnabled(): the anonymous endpoint mints
        // links that detour through a ten second interstitial.
        if ($token === '') {
            return null;
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
