<?php

declare(strict_types=1);

namespace App\Services\X;

use App\Enums\CredentialType;
use App\Exceptions\XApiException;
use App\Exceptions\XAuthException;
use App\Exceptions\XRateLimitException;
use App\Models\XAccount;
use App\Services\X\DTO\RateLimitSnapshot;
use App\Services\X\DTO\TweetPayload;
use App\Services\X\DTO\TweetResult;
use App\Support\ProxyFailure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A per-account view of the X API.
 *
 * Every call is bound to one XAccount so the proxy, the signing credentials and
 * the rate-limit bucket all travel together -- there is deliberately no
 * "global" client that could post as the wrong account.
 */
final class XApiClient
{
    private const API = 'https://api.x.com';

    private ?RateLimitSnapshot $lastRateLimit = null;

    public function __construct(
        private readonly XAccount $account,
        private readonly XOAuth2Service $oauth,
        private readonly XRateLimiter $limiter,
    ) {}

    public function lastRateLimit(): ?RateLimitSnapshot
    {
        return $this->lastRateLimit;
    }

    /** POST /2/tweets */
    public function createTweet(TweetPayload $payload): TweetResult
    {
        $response = $this->send('POST', '/2/tweets', json: $payload->toArray());

        return TweetResult::fromResponse($response->json() ?? []);
    }

    /** DELETE /2/tweets/{id} */
    public function deleteTweet(string $tweetId): bool
    {
        $response = $this->send('DELETE', "/2/tweets/{$tweetId}");

        return (bool) data_get($response->json(), 'data.deleted', false);
    }

    /** GET /2/users/me -- used to confirm a token works and refresh the profile cache. */
    public function me(): array
    {
        $response = $this->send('GET', '/2/users/me', query: [
            'user.fields' => 'id,name,username,profile_image_url,public_metrics,verified_type,location',
        ]);

        return $response->json('data') ?? [];
    }

    /** GET /2/tweets?ids=... with organic metrics for the analytics sync. */
    public function tweetMetrics(array $tweetIds): array
    {
        if ($tweetIds === []) {
            return [];
        }

        $response = $this->send('GET', '/2/tweets', query: [
            'ids' => implode(',', array_slice($tweetIds, 0, 100)),
            'tweet.fields' => 'public_metrics,non_public_metrics,organic_metrics,created_at',
        ]);

        return $response->json('data') ?? [];
    }

    /**
     * The single choke point every request goes through: auth, proxy, retries,
     * rate-limit accounting and error normalisation all live here.
     */
    /**
     * @param  array  $multipart  Guzzle multipart parts; wins over $json when set
     */
    public function send(string $method, string $path, array $query = [], array $json = [], ?callable $configure = null, array $multipart = []): Response
    {
        $request = $this->request();

        if ($configure !== null) {
            $request = $configure($request);
        }

        $url = str_starts_with($path, 'http') ? $path : self::API.$path;

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        $request = $this->authenticate($request, $method, $url, $json);

        /*
         * Multipart has to be handed to Guzzle as a request option. Building it
         * on the PendingRequest with asMultipart()/attach() and then calling
         * send() without options drops it -- the chunk never leaves, and X
         * answers "Missing media field in JSON" about a body it never received.
         *
         * OAuth 1.0a deliberately gets no body here: only form-encoded bodies
         * belong in the signature base string, never multipart.
         */
        $response = match (true) {
            $multipart !== [] => $request->asMultipart()->send($method, $url, ['multipart' => $multipart]),
            $json !== [] => $request->send($method, $url, ['json' => $json]),
            default => $request->send($method, $url),
        };

        $this->lastRateLimit = RateLimitSnapshot::fromHeaders($response->headers());

        if ($this->account->app !== null && $this->lastRateLimit !== null) {
            $this->limiter->syncFromResponse($this->account->app, $this->lastRateLimit);
        }

        return $this->guard($response);
    }

    private function request(): PendingRequest
    {
        // No ->throw(): every non-2xx is normalised by guard() into an
        // XApiException with a stable error code, so the publisher can decide
        // retry-vs-fail without parsing Guzzle exceptions.
        $request = Http::acceptJson()
            ->timeout(config('xpost.http.timeout', 30))
            ->connectTimeout(config('xpost.http.connect_timeout', 10))
            ->withUserAgent(config('xpost.http.user_agent', 'Xpost/1.0'));

        if ($proxy = $this->account->proxy) {
            $request = $request->withOptions(['proxy' => $proxy->toUri()]);
        }

        return $request;
    }

    private function authenticate(PendingRequest $request, string $method, string $url, array $json): PendingRequest
    {
        if ($this->account->credential_type === CredentialType::OAuth1) {
            $app = $this->account->app;

            if ($app === null || ! $app->supportsOAuth1()) {
                throw new XAuthException('Account '.$this->account->handle().' has no OAuth 1.0a app credentials.');
            }

            $signer = new XOAuth1Signer(
                consumerKey: (string) $app->consumer_key,
                consumerSecret: (string) $app->consumer_secret,
                token: (string) $this->account->access_token,
                tokenSecret: (string) $this->account->token_secret,
            );

            // JSON bodies are not form-encoded, so they take no part in the signature.
            return $request->withHeader('Authorization', $signer->authorizationHeader($method, $url));
        }

        return $request->withToken($this->oauth->freshAccessToken($this->account));
    }

    private function guard(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        /*
         * A 407 could only have come from the proxy, so it must not be filed as
         * X's answer. Caught here rather than left to callers because this is
         * the choke point every request passes -- and the alternative was a log
         * line reading "X API error" about a request X never received, and a
         * failed column reading "HTTP request returned status code 407".
         */
        if ($explained = ProxyFailure::fromStatus($response->status(), $this->account->proxy)) {
            Log::channel('xpost')->warning('Proxy refused the request', [
                'account_id' => $this->account->id,
                'proxy_id' => $this->account->proxy?->id,
                'status' => $response->status(),
            ]);

            throw new XApiException($explained, 407, 'proxy_rejected', []);
        }

        $body = $response->json() ?? ['detail' => $response->body()];

        Log::channel('xpost')->warning('X API error', [
            'account_id' => $this->account->id,
            'status' => $response->status(),
            'body' => $body,
        ]);

        $error = XApiException::fromResponseBody($response->status(), $body, $response->body());

        /*
         * A spent balance can arrive as a 429, and it must not be mistaken for
         * a rate limit: waiting out a window does not add credit, and the
         * publisher would keep rescheduling into a wall.
         */
        if ($response->status() === 429 && ! $error->isOutOfCredit()) {
            throw new XRateLimitException(
                'Rate limited by X for '.$this->account->handle(),
                $this->lastRateLimit?->resetAt,
                $body,
            );
        }

        throw $error;
    }
}
