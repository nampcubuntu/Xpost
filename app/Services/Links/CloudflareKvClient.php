<?php

declare(strict_types=1);

namespace App\Services\Links;

use App\Exceptions\XApiException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The Cloudflare Workers KV REST API, for one namespace.
 *
 * Xpost writes the keys a Worker reads, so the pairs can be managed from here
 * instead of by hand in the Cloudflare dashboard -- which does not scale past
 * a few dozen and has no idea which post a key belongs to.
 *
 * Two things about KV shape the design and are easy to be caught by:
 *
 *  - Writes are eventually consistent. A key written now is not readable
 *    everywhere for up to about a minute, so anything that publishes a link
 *    has to confirm the key is live first -- a crawler that arrives early
 *    gets a 404 and caches it.
 *  - Bulk write takes many pairs in one request, which is the difference
 *    between one call and a thousand at this pool's size.
 */
final class CloudflareKvClient
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    /** Cloudflare's own documented ceiling for one bulk request. */
    public const BULK_LIMIT = 10_000;

    public function __construct(
        private readonly string $accountId,
        private readonly string $namespaceId,
        private readonly string $token,
        private readonly int $timeout = 30,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            accountId: (string) config('xpost.links.account_id'),
            namespaceId: (string) config('xpost.links.namespace_id'),
            token: (string) config('xpost.links.api_token'),
            timeout: (int) config('xpost.http.timeout', 30),
        );
    }

    public function isConfigured(): bool
    {
        return filled($this->accountId) && filled($this->namespaceId) && filled($this->token);
    }

    /**
     * One page of keys, newest first is not available -- KV lists
     * lexicographically and offers a cursor, nothing else.
     *
     * @return array{keys: list<array{name: string}>, cursor: ?string}
     */
    public function listKeys(?string $prefix = null, ?string $cursor = null, int $limit = 100): array
    {
        $response = $this->request()->get($this->url('keys'), array_filter([
            'prefix' => $prefix,
            'cursor' => $cursor,
            'limit' => $limit,
        ]));

        $body = $this->guard($response)->json();

        return [
            'keys' => array_map(
                fn (array $key) => ['name' => (string) $key['name']],
                (array) ($body['result'] ?? []),
            ),
            // Cloudflare sends an empty string rather than null on the last page.
            'cursor' => filled($body['result_info']['cursor'] ?? null)
                ? (string) $body['result_info']['cursor']
                : null,
        ];
    }

    /** null when the key is absent, which KV reports as a 404. */
    public function get(string $key): ?string
    {
        $response = $this->request()->get($this->url('values/'.rawurlencode($key)));

        if ($response->status() === 404) {
            return null;
        }

        return $this->guard($response)->body();
    }

    public function put(string $key, string $value): void
    {
        /*
         * multipart, not a JSON body: this endpoint takes the value as a raw
         * form field, and sending JSON stores the quoted, escaped text -- which
         * reads back as a valid string and only looks wrong at the Worker.
         */
        $response = $this->request()
            ->asMultipart()
            ->put($this->url('values/'.rawurlencode($key)), [
                ['name' => 'value', 'contents' => $value],
                ['name' => 'metadata', 'contents' => '{}'],
            ]);

        $this->guard($response);
    }

    /**
     * @param  array<string, string>  $pairs
     * @return int how many were written
     */
    public function putMany(array $pairs): int
    {
        $written = 0;

        foreach (array_chunk($pairs, self::BULK_LIMIT, preserve_keys: true) as $chunk) {
            $body = [];

            foreach ($chunk as $key => $value) {
                $body[] = ['key' => (string) $key, 'value' => $value];
            }

            $this->guard($this->request()->asJson()->put($this->url('bulk'), $body));
            $written += count($body);
        }

        return $written;
    }

    public function delete(string $key): void
    {
        $response = $this->request()->delete($this->url('values/'.rawurlencode($key)));

        // Deleting something already gone is the state the caller wanted.
        if ($response->status() === 404) {
            return;
        }

        $this->guard($response);
    }

    private function request()
    {
        return Http::withToken($this->token)->acceptJson()->timeout($this->timeout);
    }

    private function url(string $path): string
    {
        return self::BASE."/accounts/{$this->accountId}/storage/kv/namespaces/{$this->namespaceId}/{$path}";
    }

    /**
     * Cloudflare answers 200 with success:false for some failures, so the
     * status alone is not the answer.
     */
    private function guard(Response $response): Response
    {
        $body = $response->json();

        if ($response->successful() && ($body['success'] ?? true) === true) {
            return $response;
        }

        throw new XApiException(
            (string) (data_get($body, 'errors.0.message') ?? 'Cloudflare KV error'),
            $response->status(),
            'cloudflare_kv_error',
            (array) $body,
        );
    }
}
