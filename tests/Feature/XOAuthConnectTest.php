<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\CredentialType;
use App\Models\XAccount;
use App\Models\XApp;
use App\Services\X\XOAuth2Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Connecting an X account end to end, against a faked X.
 *
 * This path broke twice in a row on `$fillable` omissions -- once for
 * usage_period_started_on on XApp, once for last_synced_at on XAccount -- and
 * each failure burned a single-use authorization code, so the only way to
 * retry was to walk the whole consent screen again. A test costs nothing and
 * catches the entire class before it reaches X.
 */
class XOAuthConnectTest extends TestCase
{
    use RefreshDatabase;

    private $team;

    private XApp $xApp;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team] = $this->tenant();

        $this->xApp = XApp::factory()->create([
            'team_id' => $this->team->id,
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'callback_url' => 'https://dev.example.test/x/oauth/callback',
            'max_accounts' => 500,
        ]);
    }

    /**
     * Overrides applied to the profile X returns, read at request time.
     *
     * Held in a property rather than re-registering the fake: Http::fake()
     * merges into the existing stubs instead of replacing them, so a second
     * call never takes effect -- the first matching stub still wins.
     */
    private array $profile = [];

    /** Fake the token exchange and GET /2/users/me. */
    private function fakeX(array $profile = []): void
    {
        $this->profile = $profile;

        Http::fake([
            'api.x.com/2/oauth2/token' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 7200,
                'scope' => 'tweet.read tweet.write users.read offline.access',
            ]),
            'api.x.com/2/users/me*' => fn () => Http::response([
                'data' => array_merge([
                    'id' => '1234567890',
                    'username' => 'nguoidung',
                    'name' => 'Người Dùng',
                    'profile_image_url' => 'https://pbs.twimg.com/a.jpg',
                    'verified_type' => 'none',
                    'location' => 'Hà Nội, Việt Nam',
                    'public_metrics' => [
                        'followers_count' => 1234,
                        'following_count' => 567,
                        'tweet_count' => 89,
                    ],
                ], $this->profile),
            ]),
        ]);
    }

    /** Start a real authorisation so the state row and verifier are genuine. */
    private function startFlow(): string
    {
        $url = app(XOAuth2Service::class)->authorizationUrl($this->xApp);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['state'];
    }

    #[Test]
    public function it_connects_an_account_from_a_callback(): void
    {
        $this->fakeX();

        $account = app(XOAuth2Service::class)->handleCallback($this->startFlow(), 'auth-code');

        $this->assertSame('nguoidung', $account->username);
        $this->assertSame('1234567890', $account->x_user_id);
        $this->assertSame($this->xApp->id, $account->x_app_id);
        $this->assertSame($this->team->id, $account->team_id);
        $this->assertSame(AccountStatus::Active, $account->status);
        $this->assertSame(CredentialType::OAuth2, $account->credential_type);
    }

    #[Test]
    public function it_stores_every_attribute_it_assigns(): void
    {
        /*
         * The regression guard. preventSilentlyDiscardingAttributes turns a
         * missing $fillable entry into an exception, so any attribute the
         * upsert assigns but the model does not allow fails this test rather
         * than a live connection.
         */
        $this->fakeX();

        $account = app(XOAuth2Service::class)->handleCallback($this->startFlow(), 'auth-code')->fresh();

        $this->assertSame('Người Dùng', $account->name);
        $this->assertSame('https://pbs.twimg.com/a.jpg', $account->avatar_url);
        $this->assertSame(1234, $account->followers_count);
        $this->assertSame(567, $account->following_count);
        $this->assertSame(89, $account->tweet_count);
        $this->assertSame('Hà Nội, Việt Nam', $account->location);
        $this->assertNotNull($account->last_synced_at);
        $this->assertNotNull($account->token_expires_at);
        $this->assertSame('new-access-token', $account->access_token);
        $this->assertSame('new-refresh-token', $account->refresh_token);
    }

    #[Test]
    public function reconnecting_the_same_account_updates_rather_than_duplicates(): void
    {
        $this->fakeX();

        $first = app(XOAuth2Service::class)->handleCallback($this->startFlow(), 'auth-code');

        // The account holder renamed themselves between the two connections.
        $this->profile = ['name' => 'Tên Mới'];
        $second = app(XOAuth2Service::class)->handleCallback($this->startFlow(), 'auth-code-2');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('Tên Mới', $second->fresh()->name);
        $this->assertSame(1, XAccount::where('x_user_id', '1234567890')->count());
    }

    #[Test]
    public function a_reconnect_clears_the_needs_reauth_flag(): void
    {
        $this->fakeX();

        $account = app(XOAuth2Service::class)->handleCallback($this->startFlow(), 'auth-code');
        $account->forceFill(['status' => AccountStatus::NeedsReauth])->save();

        $again = app(XOAuth2Service::class)->handleCallback($this->startFlow(), 'auth-code-2');

        // Reconnecting is exactly how an operator fixes a revoked token; it
        // would be useless if the account stayed out of rotation afterwards.
        $this->assertSame(AccountStatus::Active, $again->fresh()->status);
    }

    #[Test]
    public function an_unknown_state_is_rejected(): void
    {
        $this->fakeX();

        $this->expectExceptionMessage('OAuth state is unknown or has expired');

        app(XOAuth2Service::class)->handleCallback('never-issued', 'auth-code');
    }

    #[Test]
    public function a_state_cannot_be_replayed(): void
    {
        $this->fakeX();

        $state = $this->startFlow();
        app(XOAuth2Service::class)->handleCallback($state, 'auth-code');

        // Single use: a leaked callback URL must not connect a second time.
        $this->expectExceptionMessage('OAuth state is unknown or has expired');

        app(XOAuth2Service::class)->handleCallback($state, 'auth-code');
    }

    #[Test]
    public function a_failed_token_exchange_is_reported_not_swallowed(): void
    {
        Http::fake([
            'api.x.com/2/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $this->expectExceptionMessage('Token exchange failed');

        app(XOAuth2Service::class)->handleCallback($this->startFlow(), 'stale-code');
    }
}
