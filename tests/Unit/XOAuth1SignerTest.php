<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\X\XOAuth1Signer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verified against the worked example X publishes in its own "Creating a
 * signature" documentation. If this test breaks, every OAuth 1.0a account in
 * the pool stops being able to post, so it is pinned to a known-good vector
 * rather than to whatever the implementation currently produces.
 */
class XOAuth1SignerTest extends TestCase
{
    private const CONSUMER_KEY = 'xvz1evFS4wEEPTGEFPHBog';

    private const CONSUMER_SECRET = 'kAcSOqF21Fu85e7zjz7ZN2U4ZRhfV3WpwPAoE3Z7kBw';

    private const TOKEN = '370773112-GmHxMAgYyLbNEtIKZeRNFsMKPR9EyMZeS9weJAEb';

    private const TOKEN_SECRET = 'LswwdoUaIvS8ltyTt5jkRh4J50vUPVVHtR2YPi5kE';

    private const NONCE = 'kYjzVBB8Y0ZFabxSWbWovY3uYSQ2pTgmZeNu2VS4cg';

    private const TIMESTAMP = '1318622958';

    private const EXPECTED_SIGNATURE = 'hCtSmYh+iHYCEqBWrE7C7hYmtUk=';

    private function signer(): XOAuth1Signer
    {
        return new XOAuth1Signer(
            self::CONSUMER_KEY,
            self::CONSUMER_SECRET,
            self::TOKEN,
            self::TOKEN_SECRET,
        );
    }

    private function header(): string
    {
        return $this->signer()->authorizationHeader(
            'POST',
            'https://api.twitter.com/1.1/statuses/update.json?include_entities=true',
            ['status' => 'Hello Ladies + Gentlemen, a signed OAuth request!'],
            ['oauth_nonce' => self::NONCE, 'oauth_timestamp' => self::TIMESTAMP],
        );
    }

    #[Test]
    public function it_reproduces_the_documented_signature(): void
    {
        $this->assertStringContainsString(
            'oauth_signature="'.rawurlencode(self::EXPECTED_SIGNATURE).'"',
            $this->header(),
        );
    }

    #[Test]
    public function the_header_carries_every_required_parameter(): void
    {
        $header = $this->header();

        $this->assertStringStartsWith('OAuth ', $header);

        foreach ([
            'oauth_consumer_key',
            'oauth_nonce',
            'oauth_signature',
            'oauth_signature_method',
            'oauth_timestamp',
            'oauth_token',
            'oauth_version',
        ] as $parameter) {
            $this->assertStringContainsString($parameter.'="', $header);
        }
    }

    #[Test]
    public function every_request_gets_a_fresh_nonce(): void
    {
        $signer = $this->signer();

        $first = $signer->authorizationHeader('GET', 'https://api.x.com/2/users/me');
        $second = $signer->authorizationHeader('GET', 'https://api.x.com/2/users/me');

        // A replayed nonce is rejected by X, so this must never be cached.
        $this->assertNotSame($first, $second);
    }

    #[Test]
    public function query_parameters_change_the_signature(): void
    {
        $signer = $this->signer();
        $overrides = ['oauth_nonce' => self::NONCE, 'oauth_timestamp' => self::TIMESTAMP];

        $withQuery = $signer->authorizationHeader('GET', 'https://api.x.com/2/tweets?ids=1', [], $overrides);
        $without = $signer->authorizationHeader('GET', 'https://api.x.com/2/tweets', [], $overrides);

        $this->assertNotSame($withQuery, $without);
    }
}
