<?php

declare(strict_types=1);

namespace App\Services\X;

use App\Enums\CredentialType;
use App\Exceptions\XApiException;
use App\Exceptions\XAuthException;
use App\Models\XAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The X Ads API, which is a different host, version and auth model from the one
 * the rest of this codebase talks to.
 *
 * It exists here for exactly one reason: a `video_website` card. That card is
 * the only way to publish a video with the tappable "From <domain>" row under
 * it, and cards can only be created through the Ads API. The post itself still
 * goes out through the ordinary POST /2/tweets, which is what keeps it organic.
 *
 * OAuth 1.0a only -- an OAuth 2.0 bearer gets a bare 403 here, with nothing in
 * the body to say why.
 */
final class XAdsApiClient
{
    private const BASE = 'https://ads-api.x.com/12';

    public function __construct(private readonly XAccount $account) {}

    public function accountId(): string
    {
        $id = trim((string) $this->account->ads_account_id);

        if ($id === '') {
            throw new XApiException(
                'Tài khoản '.$this->account->handle().' chưa có Ads account ID.',
                errorCode: 'missing_ads_account',
            );
        }

        return $id;
    }

    /** @param  array<string, scalar>  $params */
    public function get(string $path, array $params = []): Response
    {
        return $this->send('GET', $path, $params);
    }

    /**
     * Cards outlive the posts that used them and nothing reaps them, so an
     * account accumulates them indefinitely. Deleting one is safe once no live
     * post references it -- X keeps its own copy of what a published post
     * rendered.
     */
    public function delete(string $path): Response
    {
        return $this->send('DELETE', $path);
    }

    /**
     * POST with a JSON body.
     *
     * The cards endpoint rejects a `components` array passed as a query string
     * -- "The JSON string format is invalid" -- and takes it as a JSON body
     * instead. A JSON body takes no part in an OAuth 1.0a signature, so nothing
     * is signed beyond the URL.
     */
    public function postJson(string $path, array $body): Response
    {
        return $this->send('POST', $path, [], $body);
    }

    private function send(string $method, string $path, array $params = [], array $json = []): Response
    {
        $url = self::BASE.'/'.ltrim($path, '/');

        $request = Http::withHeaders([
            // Query params are part of the signature base string; a JSON body is not.
            'Authorization' => $this->signer()->authorizationHeader($method, $url, $params),
        ])->timeout((int) config('xpost.http.timeout', 30));

        $full = $params === [] ? $url : $url.'?'.http_build_query($params);

        $response = $json === []
            ? $request->send($method, $full)
            : $request->asJson()->send($method, $full, ['json' => $json]);

        return $this->guard($response);
    }

    private function signer(): XOAuth1Signer
    {
        $app = $this->account->app;

        if ($this->account->credential_type !== CredentialType::OAuth1 || blank($this->account->token_secret)) {
            throw new XAuthException(
                'Ads API cần OAuth 1.0a. Tài khoản '.$this->account->handle()
                .' đang dùng '.$this->account->credential_type->value.', chưa có token secret.'
            );
        }

        if ($app === null || ! $app->supportsOAuth1()) {
            throw new XAuthException('X app của '.$this->account->handle().' thiếu Consumer Key/Secret.');
        }

        return new XOAuth1Signer(
            consumerKey: (string) $app->consumer_key,
            consumerSecret: (string) $app->consumer_secret,
            token: (string) $this->account->access_token,
            tokenSecret: (string) $this->account->token_secret,
        );
    }

    /**
     * Ads API errors carry their own envelope, unrelated to the v2 problem
     * format, so they are unpacked here rather than by XApiException.
     */
    private function guard(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $error = data_get($response->json(), 'errors.0', []);

        throw new XApiException(
            (string) ($error['message'] ?? 'Ads API error'),
            $response->status(),
            (string) ($error['code'] ?? 'ads_api_error'),
            $response->json() ?? [],
        );
    }
}
