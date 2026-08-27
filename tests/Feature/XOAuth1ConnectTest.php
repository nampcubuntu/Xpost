<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Models\XAccount;
use App\Models\XApp;
use App\Models\XOAuthState;
use App\Services\X\XOAuth1Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The 3-legged OAuth 1.0a handshake.
 *
 * This flow exists because the Ads API accepts nothing else, and only an
 * account that can reach the Ads API can mint its own `video_website` card. An
 * account connected through OAuth 2.0 publishes fine but can never mint.
 */
class XOAuth1ConnectTest extends TestCase
{
    use RefreshDatabase;

    private $team;

    private $user;

    private XApp $xApp;

    /** @var array<string, mixed> */
    private array $stubs = [];

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team, $this->user] = $this->tenant();

        $this->xApp = XApp::factory()->create([
            'team_id' => $this->team->id,
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
            'callback_url' => 'https://xpost.test/x/oauth/callback',
        ]);
    }

    /**
     * Http::fake() merges stubs rather than replacing them, so a second call
     * never takes effect. Overrides are collected here and faked once.
     */
    private function fakeX(array $overrides = []): void
    {
        Http::fake(array_merge([
            'api.x.com/oauth/request_token' => Http::response(
                'oauth_token=reqtok&oauth_token_secret=reqsecret&oauth_callback_confirmed=true'
            ),
            'api.x.com/oauth/access_token' => Http::response(
                'oauth_token=acctok&oauth_token_secret=accsecret&user_id=42&screen_name=nancy_2z'
            ),
            'api.x.com/2/users/*' => Http::response(['data' => [
                'id' => '42', 'username' => 'nancy_2z', 'name' => 'Nancy',
                'public_metrics' => ['followers_count' => 7],
            ]]),
            'ads-api.x.com/12/accounts' => Http::response(['data' => [
                ['id' => '18ce55wllne', 'approval_status' => 'ACCEPTED', 'deleted' => false],
            ]]),
        ], $overrides, $this->stubs));
    }

    #[Test]
    public function the_first_leg_asks_x_for_a_request_token_and_parks_its_secret(): void
    {
        $this->fakeX();

        $url = app(XOAuth1Service::class)->authorizationUrl($this->xApp, $this->user->id);

        $this->assertStringStartsWith('https://api.x.com/oauth/authorize?', $url);
        $this->assertStringContainsString('oauth_token=reqtok', $url);

        // The secret from leg 1 is needed again in leg 3, and nowhere else.
        $state = XOAuthState::withoutGlobalScopes()->where('state', 'reqtok')->firstOrFail();
        $this->assertSame('reqsecret', $state->code_verifier);
        $this->assertTrue($state->isUsable());
    }

    #[Test]
    public function the_request_token_leg_signs_without_an_oauth_token(): void
    {
        // There is no token yet at this point, and X rejects an empty
        // oauth_token outright rather than ignoring it.
        $this->fakeX();

        app(XOAuth1Service::class)->authorizationUrl($this->xApp);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'oauth/request_token')) {
                return false;
            }

            $auth = $request->header('Authorization')[0];

            return ! str_contains($auth, 'oauth_token=')
                && str_contains($auth, 'oauth_callback')
                && str_contains($auth, 'oauth_signature');
        });
    }

    #[Test]
    public function connecting_many_accounts_forces_the_login_screen(): void
    {
        /*
         * A reused browser session silently reconnects whoever is already
         * logged in. That looks like success and is not -- which matters when
         * the workflow is connecting a long run of different accounts.
         */
        // X mints a distinct request token per handshake, and x_oauth_states
        // enforces that -- so the two calls here cannot share a stub.
        $this->stubs = ['api.x.com/oauth/request_token' => Http::sequence()
            ->push('oauth_token=first&oauth_token_secret=s&oauth_callback_confirmed=true')
            ->push('oauth_token=second&oauth_token_secret=s&oauth_callback_confirmed=true')];
        $this->fakeX();

        $service = app(XOAuth1Service::class);

        $this->assertStringContainsString('force_login=true', $service->authorizationUrl($this->xApp));
        $this->assertStringNotContainsString(
            'force_login',
            $service->authorizationUrl($this->xApp, forceLogin: false),
        );
    }

    #[Test]
    public function a_rejected_callback_url_is_reported_at_the_first_leg(): void
    {
        // Otherwise the handshake fails two legs later with a message that
        // names nothing.
        $this->stubs = ['api.x.com/oauth/request_token' => Http::response(
            'oauth_token=reqtok&oauth_token_secret=reqsecret&oauth_callback_confirmed=false'
        )];
        $this->fakeX();

        $this->expectExceptionMessageMatches('/Callback URI/');

        app(XOAuth1Service::class)->authorizationUrl($this->xApp);
    }

    #[Test]
    public function the_final_leg_stores_an_oauth1_account_with_its_token_secret(): void
    {
        $this->fakeX();

        $service = app(XOAuth1Service::class);
        $service->authorizationUrl($this->xApp, $this->user->id);

        $account = $service->handleCallback('reqtok', 'verifier123');

        $this->assertSame(CredentialType::OAuth1, $account->credential_type);
        $this->assertSame('acctok', $account->access_token);
        // Without the secret the Ads API refuses every request.
        $this->assertSame('accsecret', $account->token_secret);
        $this->assertSame('nancy_2z', $account->username);
        $this->assertNull($account->token_expires_at);
    }

    #[Test]
    public function the_ads_account_is_discovered_rather_than_typed_in(): void
    {
        // A hand-entered id fails later, deep in a publish worker, with an Ads
        // API error that names nothing.
        $this->fakeX();

        $service = app(XOAuth1Service::class);
        $service->authorizationUrl($this->xApp);

        $this->assertSame('18ce55wllne', $service->handleCallback('reqtok', 'v')->ads_account_id);
    }

    #[Test]
    public function an_account_without_ads_access_still_connects(): void
    {
        /*
         * It can publish cards someone else minted; it just cannot mint its
         * own. Refusing the whole connect over that would be wrong.
         */
        $this->stubs = ['ads-api.x.com/12/accounts' => Http::response(['errors' => [['message' => 'no']]], 403)];
        $this->fakeX();

        $service = app(XOAuth1Service::class);
        $service->authorizationUrl($this->xApp);

        $account = $service->handleCallback('reqtok', 'v');

        $this->assertNull($account->ads_account_id);
        $this->assertSame(CredentialType::OAuth1, $account->credential_type);
    }

    #[Test]
    public function an_unapproved_ads_account_is_still_attached(): void
    {
        /*
         * approval_status gates running ads, not creating creative. A REJECTED
         * account mints cards perfectly well -- measured against a live one,
         * after an earlier version of this filtered on ACCEPTED and reported a
         * usable account as having no ads access at all.
         */
        $this->stubs = ['ads-api.x.com/12/accounts' => Http::response(['data' => [
            ['id' => '18ce55x0crz', 'approval_status' => 'REJECTED', 'deleted' => false],
        ]])];
        $this->fakeX();

        $service = app(XOAuth1Service::class);
        $service->authorizationUrl($this->xApp);

        $this->assertSame('18ce55x0crz', $service->handleCallback('reqtok', 'v')->ads_account_id);
    }

    #[Test]
    public function a_deleted_ads_account_is_skipped(): void
    {
        $this->stubs = ['ads-api.x.com/12/accounts' => Http::response(['data' => [
            ['id' => 'gone', 'approval_status' => 'ACCEPTED', 'deleted' => true],
        ]])];
        $this->fakeX();

        $service = app(XOAuth1Service::class);
        $service->authorizationUrl($this->xApp);

        $this->assertNull($service->handleCallback('reqtok', 'v')->ads_account_id);
    }

    #[Test]
    public function an_approved_ads_account_wins_when_there_is_a_choice(): void
    {
        // Unapproved works, but approved is the one that also runs ads.
        $this->stubs = ['ads-api.x.com/12/accounts' => Http::response(['data' => [
            ['id' => 'rejected-one', 'approval_status' => 'REJECTED', 'deleted' => false],
            ['id' => 'accepted-one', 'approval_status' => 'ACCEPTED', 'deleted' => false],
        ]])];
        $this->fakeX();

        $service = app(XOAuth1Service::class);
        $service->authorizationUrl($this->xApp);

        $this->assertSame('accepted-one', $service->handleCallback('reqtok', 'v')->ads_account_id);
    }

    #[Test]
    public function a_state_cannot_be_replayed(): void
    {
        $this->fakeX();

        $service = app(XOAuth1Service::class);
        $service->authorizationUrl($this->xApp);
        $service->handleCallback('reqtok', 'v');

        $this->expectExceptionMessageMatches('/hết hạn|không tồn tại/u');

        $service->handleCallback('reqtok', 'v');
    }

    #[Test]
    public function the_callback_route_tells_the_two_flows_apart(): void
    {
        // One callback URL serves both, because X allows only a fixed list per
        // app and every entry has to be registered by hand.
        $this->fakeX();

        app(XOAuth1Service::class)->authorizationUrl($this->xApp);

        $this->actingAs($this->user)
            ->get('/x/oauth/callback?oauth_token=reqtok&oauth_verifier=v')
            ->assertRedirect('/accounts?connected=nancy_2z');

        $this->assertDatabaseHas('x_accounts', [
            'x_user_id' => '42',
            'credential_type' => CredentialType::OAuth1->value,
        ]);
    }

    #[Test]
    public function cancelling_the_oauth1_screen_reports_the_reason(): void
    {
        // X says ?denied=<token> here, not ?error= as OAuth 2.0 does.
        $this->actingAs($this->user)
            ->get('/x/oauth/callback?denied=reqtok')
            ->assertRedirect('/accounts?oauth_error=reqtok');
    }

    #[Test]
    public function reconnecting_upgrades_an_existing_oauth2_account_in_place(): void
    {
        /*
         * The same handle connected again through OAuth 1.0a is the same
         * account, not a second one -- and it keeps its history, caps and
         * schedule rather than starting over.
         */
        $existing = XAccount::factory()->create([
            'team_id' => $this->team->id,
            'x_app_id' => $this->xApp->id,
            'x_user_id' => '42',
            'credential_type' => CredentialType::OAuth2,
            'daily_post_cap' => 17,
        ]);

        $this->fakeX();

        $service = app(XOAuth1Service::class);
        $service->authorizationUrl($this->xApp);
        $account = $service->handleCallback('reqtok', 'v');

        $this->assertSame($existing->id, $account->id);
        $this->assertSame(17, $account->daily_post_cap);
        $this->assertSame(CredentialType::OAuth1, $account->credential_type);
    }
}
