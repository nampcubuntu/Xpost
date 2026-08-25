<?php

declare(strict_types=1);

namespace App\Services\X;

/**
 * OAuth 1.0a HMAC-SHA1 request signing, per RFC 5849.
 *
 * Needed for accounts imported as legacy user tokens, and for the v1.1 chunked
 * media upload endpoint which never gained an OAuth 2.0 equivalent for some
 * app configurations.
 */
final class XOAuth1Signer
{
    public function __construct(
        private readonly string $consumerKey,
        private readonly string $consumerSecret,
        private readonly string $token,
        private readonly string $tokenSecret,
    ) {}

    /**
     * Build the Authorization header value.
     *
     * @param  array<string, scalar>  $params  query + form params that take part in the signature
     * @param  array<string, string>  $overrides  fixed oauth_nonce / oauth_timestamp, for tests only
     */
    public function authorizationHeader(string $method, string $url, array $params = [], array $overrides = []): string
    {
        $oauth = array_merge([
            'oauth_consumer_key' => $this->consumerKey,
            'oauth_nonce' => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => (string) time(),
            'oauth_token' => $this->token,
            'oauth_version' => '1.0',
        ], $overrides);

        $oauth['oauth_signature'] = $this->sign($method, $url, array_merge($oauth, $params));

        $parts = [];
        foreach ($oauth as $key => $value) {
            $parts[] = rawurlencode($key).'="'.rawurlencode($value).'"';
        }

        return 'OAuth '.implode(', ', $parts);
    }

    private function sign(string $method, string $url, array $params): string
    {
        // Query string params are part of the signature base but not the URL.
        $parsed = parse_url($url);
        $base = ($parsed['scheme'] ?? 'https').'://'.($parsed['host'] ?? '').($parsed['path'] ?? '');

        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $queryParams);
            $params = array_merge($params, $queryParams);
        }

        // RFC 5849 3.4.1.3.2: percent-encode, then sort by encoded key (then value).
        $encoded = [];
        foreach ($params as $key => $value) {
            $encoded[rawurlencode((string) $key)] = rawurlencode((string) $value);
        }
        uksort($encoded, 'strcmp');

        $pairs = [];
        foreach ($encoded as $key => $value) {
            $pairs[] = "{$key}={$value}";
        }

        $signatureBase = strtoupper($method)
            .'&'.rawurlencode($base)
            .'&'.rawurlencode(implode('&', $pairs));

        $signingKey = rawurlencode($this->consumerSecret).'&'.rawurlencode($this->tokenSecret);

        return base64_encode(hash_hmac('sha1', $signatureBase, $signingKey, true));
    }
}
