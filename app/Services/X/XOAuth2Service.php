<?php

declare(strict_types=1);

namespace App\Services\X;

use App\Enums\AccountStatus;
use App\Enums\CredentialType;
use App\Exceptions\XAuthException;
use App\Models\XAccount;
use App\Models\XApp;
use App\Models\XOAuthState;
use App\Support\TeamContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * OAuth 2.0 Authorization Code + PKCE against X.
 *
 * Refreshes are wrapped in a Redis lock keyed on the account: with several
 * workers publishing for the same account at once, an unguarded refresh races
 * and the loser ends up holding a token X has already rotated away.
 */
final class XOAuth2Service
{
    private const AUTHORIZE_URL = 'https://x.com/i/oauth2/authorize';

    private const TOKEN_URL = 'https://api.x.com/2/oauth2/token';

    public const DEFAULT_SCOPES = [
        'tweet.read', 'tweet.write', 'users.read',
        'offline.access', 'media.write',
    ];

    /** Build the consent URL and persist the PKCE verifier for the callback. */
    public function authorizationUrl(XApp $app, ?int $userId = null, ?string $redirectTo = null): string
    {
        if (! $app->supportsOAuth2()) {
            throw new XAuthException("X app [{$app->name}] is missing OAuth 2.0 client credentials.");
        }

        $verifier = Str::random(96);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state = Str::random(48);

        XOAuthState::create([
            'team_id' => $app->team_id,
            'x_app_id' => $app->id,
            'user_id' => $userId,
            'state' => $state,
            'code_verifier' => $verifier,
            'redirect_to' => $redirectTo,
            'expires_at' => now()->addMinutes(15),
        ]);

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $app->client_id,
            'redirect_uri' => $this->redirectUri($app),
            'scope' => implode(' ', $app->scopes ?: self::DEFAULT_SCOPES),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /** Exchange the callback code for tokens and upsert the account. */
    public function handleCallback(string $state, string $code): XAccount
    {
        $record = TeamContext::runUnscoped(
            fn () => XOAuthState::withoutGlobalScopes()->where('state', $state)->first()
        );

        if ($record === null || ! $record->isUsable()) {
            throw new XAuthException('OAuth state is unknown or has expired. Start the authorisation again.');
        }

        $app = $record->app;

        $response = Http::asForm()
            ->withBasicAuth((string) $app->client_id, (string) $app->client_secret)
            ->post(self::TOKEN_URL, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri($app),
                'code_verifier' => $record->code_verifier,
            ]);

        if ($response->failed()) {
            throw new XAuthException('Token exchange failed: '.$response->body(), $response->json() ?? []);
        }

        $record->forceFill(['consumed_at' => now()])->save();

        return TeamContext::runAs($app->team_id, fn () => $this->upsertAccount($app, $response->json()));
    }

    /** Return a usable bearer token, refreshing first when it is about to lapse. */
    public function freshAccessToken(XAccount $account): string
    {
        if ($account->credential_type !== CredentialType::OAuth2) {
            throw new XAuthException('Account '.$account->handle().' does not use OAuth 2.0.');
        }

        if (! $account->tokenIsExpiring()) {
            return (string) $account->access_token;
        }

        return $this->refresh($account);
    }

    /** @return string the new access token */
    public function refresh(XAccount $account): string
    {
        $lock = Cache::lock("xpost:token-refresh:{$account->id}", 20);

        // Another worker is already refreshing: wait for it, then re-read.
        if (! $lock->get()) {
            $lock->block(15);
            $lock->release();

            return (string) $account->fresh()->access_token;
        }

        try {
            $app = $account->app;

            if ($app === null || ! $app->supportsOAuth2()) {
                throw new XAuthException('Account '.$account->handle().' has no OAuth 2.0 app to refresh against.');
            }

            if (blank($account->refresh_token)) {
                $this->markNeedsReauth($account, 'No refresh token on file (offline.access scope missing?).');

                throw new XAuthException('Account '.$account->handle().' cannot be refreshed.');
            }

            $response = Http::asForm()
                ->withBasicAuth((string) $app->client_id, (string) $app->client_secret)
                ->retry(2, 500, throw: false)
                ->post(self::TOKEN_URL, [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $account->refresh_token,
                    'client_id' => $app->client_id,
                ]);

            if ($response->failed()) {
                $this->markNeedsReauth($account, 'Refresh rejected: '.$response->body());

                throw new XAuthException('Refresh failed for '.$account->handle(), $response->json() ?? []);
            }

            $data = $response->json();

            $account->forceFill([
                'access_token' => $data['access_token'],
                // X rotates the refresh token on every use; losing this write locks the account out.
                'refresh_token' => $data['refresh_token'] ?? $account->refresh_token,
                'token_expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 7200)),
                'token_refreshed_at' => now(),
                'status' => AccountStatus::Active,
                'last_error' => null,
            ])->save();

            return (string) $data['access_token'];
        } finally {
            $lock->release();
        }
    }

    private function upsertAccount(XApp $app, array $token): XAccount
    {
        $profile = Http::withToken($token['access_token'])
            ->acceptJson()
            ->get('https://api.x.com/2/users/me', [
                'user.fields' => 'id,name,username,profile_image_url,public_metrics,verified_type,location',
            ])
            ->json('data');

        if (blank($profile['id'] ?? null)) {
            throw new XAuthException('Could not read the profile for the newly authorised account.');
        }

        return XAccount::updateOrCreate(
            ['team_id' => $app->team_id, 'x_user_id' => (string) $profile['id']],
            [
                'x_app_id' => $app->id,
                'username' => $profile['username'],
                'name' => $profile['name'] ?? null,
                'avatar_url' => $profile['profile_image_url'] ?? null,
                'followers_count' => data_get($profile, 'public_metrics.followers_count', 0),
                'following_count' => data_get($profile, 'public_metrics.following_count', 0),
                'tweet_count' => data_get($profile, 'public_metrics.tweet_count', 0),
                'verified_type' => $profile['verified_type'] ?? null,
                // Free text the account holder typed. The only thing X gives us
                // to work out what "09:00 their time" means.
                'location' => $profile['location'] ?? null,
                'credential_type' => CredentialType::OAuth2,
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? null,
                'scopes' => explode(' ', (string) ($token['scope'] ?? '')),
                'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 7200)),
                'status' => AccountStatus::Active,
                'last_synced_at' => now(),
            ],
        );
    }

    private function redirectUri(XApp $app): string
    {
        return $app->callback_url ?: route('x.oauth.callback');
    }

    private function markNeedsReauth(XAccount $account, string $reason): void
    {
        $account->forceFill([
            'status' => AccountStatus::NeedsReauth,
            'last_error' => $reason,
            'last_error_at' => now(),
        ])->save();
    }
}
