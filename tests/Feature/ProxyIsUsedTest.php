<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Models\Proxy;
use App\Models\Team;
use App\Models\XAccount;
use App\Models\XApp;
use App\Services\X\XAdsApiClient;
use App\Services\X\XApiClientFactory;
use App\Services\X\XOAuth2Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every request an account makes leaves through that account's proxy.
 *
 * Assigning a proxy is not the same as using one, and the difference is
 * invisible from the outside: the page shows a proxy on the row either way.
 * Worse than not using it at all is using it for some calls and not others --
 * X then sees one account acting from two addresses within seconds, which is a
 * cleaner signal of automation than a shared IP ever was.
 *
 * The Guzzle options reach the fake callback's second argument, so the proxy
 * can be asserted on rather than inferred.
 */
class ProxyIsUsedTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private XAccount $account;

    private Proxy $proxy;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team, $user] = $this->tenant();
        $this->actingAs($user->refresh());

        $this->proxy = Proxy::factory()->create([
            'team_id' => $this->team->id,
            'scheme' => 'http',
            'host' => '198.51.100.7',
            'port' => 8080,
            'username' => 'u',
            'password' => 'p',
        ]);

        $app = XApp::factory()->create([
            'team_id' => $this->team->id,
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
        ]);

        $this->account = XAccount::factory()->create([
            'team_id' => $this->team->id,
            'x_app_id' => $app->id,
            'proxy_id' => $this->proxy->id,
            'credential_type' => CredentialType::OAuth1,
            'access_token' => 'at',
            'token_secret' => 'ts',
            'ads_account_id' => '18ce55wllne',
        ]);
    }

    /** The proxy URIs seen by every faked request, in order. */
    private function recordProxies(array &$seen): void
    {
        Http::fake(function ($request, $options) use (&$seen) {
            $seen[] = [$request->url(), $options['proxy'] ?? null];

            return Http::response(['data' => ['id' => 'x', 'card_uri' => 'c']]);
        });
    }

    #[Test]
    public function posting_goes_out_through_the_accounts_proxy(): void
    {
        $seen = [];
        $this->recordProxies($seen);

        app(XApiClientFactory::class)->for($this->account->fresh())
            ->send('POST', 'tweets', json: ['text' => 'hello']);

        $this->assertNotEmpty($seen);
        $this->assertSame($this->proxy->toUri(), $seen[0][1], $seen[0][0]);
    }

    #[Test]
    public function minting_a_card_goes_out_through_the_same_proxy(): void
    {
        /*
         * The one that mattered and was missing. A card is minted per publish,
         * seconds before the post that carries it -- so the account was
         * reaching X from its proxy and from the server's own address inside
         * the same operation.
         */
        $seen = [];
        $this->recordProxies($seen);

        (new XAdsApiClient($this->account->fresh()->loadMissing(['app', 'proxy'])))
            ->postJson('accounts/18ce55wllne/cards', ['name' => 'c']);

        $this->assertNotEmpty($seen);
        $this->assertStringContainsString('ads-api.x.com', $seen[0][0]);
        $this->assertSame($this->proxy->toUri(), $seen[0][1]);
    }

    #[Test]
    public function refreshing_a_token_goes_out_through_the_proxy(): void
    {
        /*
         * A refresh is one of the few calls X sees from an account while it is
         * otherwise idle, which makes it a particularly clean sample of where
         * that account lives. Coming from the server address while every post
         * comes from a proxy ties the whole pool back to one machine.
         */
        $app = XApp::factory()->create([
            'team_id' => $this->team->id,
            'client_id' => 'ci',
            'client_secret' => 'cs',
        ]);

        $account = XAccount::factory()->create([
            'team_id' => $this->team->id,
            'x_app_id' => $app->id,
            'proxy_id' => $this->proxy->id,
            'credential_type' => CredentialType::OAuth2,
            'access_token' => 'at',
            'refresh_token' => 'rt',
            'token_expires_at' => now()->subMinute(),
        ]);

        $seen = [];
        Http::fake(function ($request, $options) use (&$seen) {
            $seen[] = [$request->url(), $options['proxy'] ?? null];

            return Http::response(['access_token' => 'new', 'expires_in' => 7200]);
        });

        app(XOAuth2Service::class)->refresh($account->fresh());

        $this->assertNotEmpty($seen);
        $this->assertSame($this->proxy->toUri(), $seen[0][1], $seen[0][0]);
    }

    #[Test]
    public function an_account_without_a_proxy_still_goes_out_directly(): void
    {
        // No proxy is a valid state, not a reason to fail: the pool can be
        // empty, and publishing has to keep working while it is.
        $this->account->forceFill(['proxy_id' => null])->save();

        $seen = [];
        $this->recordProxies($seen);

        app(XApiClientFactory::class)->for($this->account->fresh())
            ->send('POST', 'tweets', json: ['text' => 'hello']);

        $this->assertNull($seen[0][1]);
    }
}
