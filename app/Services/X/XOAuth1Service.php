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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The 3-legged OAuth 1.0a handshake.
 *
 * OAuth 2.0 covers everything the publishing pipeline needs, so this exists for
 * one reason: the Ads API accepts nothing else, and the Ads API is the only way
 * to mint the `video_website` card that gives a post its "From <domain>" row.
 * An account connected through OAuth 2.0 can publish a card someone else
 * minted, but it can never mint its own.
 *
 * Three legs, and the middle one happens in the account holder's browser:
 *
 *   1. POST /oauth/request_token   -> a temporary token + secret
 *   2. the browser visits /oauth/authorize and X redirects back with a verifier
 *   3. POST /oauth/access_token    -> the long-lived token + secret
 *
 * The temporary secret from leg 1 is needed again in leg 3, so it is parked in
 * x_oauth_states -- the same table the OAuth 2.0 flow uses for its PKCE
 * verifier, which is the same shape of secret with the same lifetime.
 */
final class XOAuth1Service
{
    private const REQUEST_TOKEN_URL = 'https://api.x.com/oauth/request_token';

    private const AUTHORIZE_URL = 'https://api.x.com/oauth/authorize';

    private const ACCESS_TOKEN_URL = 'https://api.x.com/oauth/access_token';

    /**
     * Leg 1 + the URL for leg 2.
     *
     * @param  bool  $forceLogin  make X show the login screen instead of reusing
     *                            the browser's current session. On by default:
     *                            the whole point of this flow is connecting many
     *                            different accounts, and a reused session
     *                            silently reconnects whoever is already logged
     *                            in -- which looks like success and is not.
     */
    public function authorizationUrl(
        XApp $app,
        ?int $userId = null,
        ?string $redirectTo = null,
        bool $forceLogin = true,
    ): string {
        if (! $app->supportsOAuth1()) {
            throw new XAuthException("X app [{$app->name}] thiếu Consumer Key/Secret nên không dùng được OAuth 1.0a.");
        }

        $callback = $this->callbackUrl($app);

        // oauth_callback takes part in the signature for this leg only.
        $signer = new XOAuth1Signer(
            consumerKey: (string) $app->consumer_key,
            consumerSecret: (string) $app->consumer_secret,
            token: '',
            tokenSecret: '',
        );

        $response = Http::withHeaders([
            'Authorization' => $signer->authorizationHeader(
                'POST',
                self::REQUEST_TOKEN_URL,
                ['oauth_callback' => $callback],
                ['oauth_callback' => $callback],
            ),
        ])->asForm()->post(self::REQUEST_TOKEN_URL);

        if ($response->failed()) {
            throw new XAuthException('Xin request token thất bại: '.$response->body());
        }

        $token = $this->parseFormBody($response->body());

        if (blank($token['oauth_token'] ?? null) || blank($token['oauth_token_secret'] ?? null)) {
            throw new XAuthException('X không trả về request token: '.$response->body());
        }

        /*
         * X echoes this back only when it accepted our callback. A false here
         * means the URL is not on the app's allow list, and the handshake would
         * fail two legs later with a message that says nothing useful.
         */
        if (($token['oauth_callback_confirmed'] ?? 'false') !== 'true') {
            throw new XAuthException(
                'X từ chối callback URL. Thêm '.$callback.' vào danh sách Callback URI của app.'
            );
        }

        XOAuthState::create([
            'team_id' => $app->team_id,
            'x_app_id' => $app->id,
            'user_id' => $userId,
            // The request token is the lookup key X hands back on the callback.
            'state' => $token['oauth_token'],
            // Reused as-is: same kind of short-lived secret, already encrypted.
            'code_verifier' => $token['oauth_token_secret'],
            'redirect_to' => $redirectTo,
            'expires_at' => now()->addMinutes(15),
        ]);

        return self::AUTHORIZE_URL.'?'.http_build_query(array_filter([
            'oauth_token' => $token['oauth_token'],
            'force_login' => $forceLogin ? 'true' : null,
        ]));
    }

    /** Leg 3: swap the verifier for the long-lived token, then store the account. */
    public function handleCallback(string $requestToken, string $verifier): XAccount
    {
        $record = TeamContext::runUnscoped(
            fn () => XOAuthState::withoutGlobalScopes()->where('state', $requestToken)->first()
        );

        if ($record === null || ! $record->isUsable()) {
            throw new XAuthException('Phiên OAuth không tồn tại hoặc đã hết hạn. Kết nối lại từ đầu.');
        }

        $app = $record->app;

        $signer = new XOAuth1Signer(
            consumerKey: (string) $app->consumer_key,
            consumerSecret: (string) $app->consumer_secret,
            token: $requestToken,
            tokenSecret: (string) $record->code_verifier,
        );

        $response = Http::withHeaders([
            'Authorization' => $signer->authorizationHeader(
                'POST',
                self::ACCESS_TOKEN_URL,
                ['oauth_verifier' => $verifier],
            ),
        ])->asForm()->post(self::ACCESS_TOKEN_URL, ['oauth_verifier' => $verifier]);

        if ($response->failed()) {
            throw new XAuthException('Đổi access token thất bại: '.$response->body());
        }

        $token = $this->parseFormBody($response->body());

        if (blank($token['oauth_token'] ?? null) || blank($token['oauth_token_secret'] ?? null)) {
            throw new XAuthException('X không trả về access token: '.$response->body());
        }

        $record->forceFill(['consumed_at' => now()])->save();

        return TeamContext::runAs($app->team_id, fn () => $this->upsertAccount($app, $token));
    }

    private function upsertAccount(XApp $app, array $token): XAccount
    {
        $signer = new XOAuth1Signer(
            consumerKey: (string) $app->consumer_key,
            consumerSecret: (string) $app->consumer_secret,
            token: $token['oauth_token'],
            tokenSecret: $token['oauth_token_secret'],
        );

        $profile = $this->fetchProfile($signer, (string) ($token['user_id'] ?? ''));

        $account = XAccount::updateOrCreate(
            ['team_id' => $app->team_id, 'x_user_id' => (string) $token['user_id']],
            [
                'x_app_id' => $app->id,
                'username' => $profile['username'] ?? ($token['screen_name'] ?? null),
                'name' => $profile['name'] ?? null,
                'avatar_url' => $profile['profile_image_url'] ?? null,
                'followers_count' => data_get($profile, 'public_metrics.followers_count', 0),
                'following_count' => data_get($profile, 'public_metrics.following_count', 0),
                'tweet_count' => data_get($profile, 'public_metrics.tweet_count', 0),
                'verified_type' => $profile['verified_type'] ?? null,
                'location' => $profile['location'] ?? null,
                'credential_type' => CredentialType::OAuth1,
                'access_token' => $token['oauth_token'],
                'token_secret' => $token['oauth_token_secret'],
                // OAuth 1.0a tokens do not expire and carry no scope list; the
                // app's permission level decides what they can do.
                'refresh_token' => null,
                'token_expires_at' => null,
                'status' => AccountStatus::Active,
                'last_synced_at' => now(),
            ],
        );

        $this->attachAdsAccount($account, $signer);

        return $account;
    }

    /**
     * Find the account's own ads account, if it has one.
     *
     * Asked here rather than typed in by hand: the id is what decides whether
     * this account can mint its own cards, and a wrong one fails later, deep in
     * a publish worker, with an Ads API error that names nothing.
     *
     * Best-effort on purpose. An account with no ads account is still perfectly
     * good for publishing -- it just cannot mint, so refusing the whole connect
     * over it would be wrong.
     *
     * approval_status is deliberately not a filter. It gates running ads, not
     * creating creative, and a REJECTED account mints cards perfectly well --
     * measured, after an earlier version of this method threw one away and
     * reported the account as having no ads access at all. ACCEPTED is only
     * preferred when there is a choice.
     */
    private function attachAdsAccount(XAccount $account, XOAuth1Signer $signer): void
    {
        try {
            $url = 'https://ads-api.x.com/12/accounts';

            $response = Http::withHeaders([
                'Authorization' => $signer->authorizationHeader('GET', $url),
            ])->timeout((int) config('xpost.http.timeout', 30))->get($url);

            /*
             * The same call answers a question nothing else could: whether this
             * app has Ads API access at all. X grants that per app and names
             * apps by a numeric id nothing here stored, so an app without it
             * was indistinguishable from an account without an ads account --
             * until a publish failed. A 403 is the app; a 200 with an empty
             * list is the account.
             */
            $this->recordAdsApiAccess($account->app, ! $response->forbidden());

            if ($response->failed()) {
                return;
            }

            $usable = collect((array) $response->json('data'))
                ->reject(fn (array $ads) => (bool) ($ads['deleted'] ?? false))
                ->sortByDesc(fn (array $ads) => ($ads['approval_status'] ?? null) === 'ACCEPTED');

            if ($usable->isNotEmpty()) {
                $account->forceFill(['ads_account_id' => $usable->first()['id']])->save();
            }
        } catch (\Throwable $e) {
            Log::channel('xpost')->info('Không đọc được ads account khi kết nối', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function recordAdsApiAccess(?XApp $app, bool $granted): void
    {
        $app?->forceFill([
            'ads_api_access' => $granted,
            'ads_api_checked_at' => now(),
        ])->save();
    }

    /** @return array<string, mixed> */
    private function fetchProfile(XOAuth1Signer $signer, string $userId): array
    {
        if ($userId === '') {
            return [];
        }

        $url = 'https://api.x.com/2/users/'.$userId;
        $params = ['user.fields' => 'id,name,username,profile_image_url,public_metrics,verified_type,location'];

        $response = Http::withHeaders([
            'Authorization' => $signer->authorizationHeader('GET', $url, $params),
        ])->acceptJson()->get($url, $params);

        return $response->successful() ? (array) $response->json('data') : [];
    }

    /**
     * Both token legs answer in form encoding, not JSON.
     *
     * @return array<string, string>
     */
    private function parseFormBody(string $body): array
    {
        parse_str(trim($body), $parsed);

        return array_map('strval', $parsed);
    }

    private function callbackUrl(XApp $app): string
    {
        return $app->callback_url ?: route('x.oauth.callback');
    }
}
