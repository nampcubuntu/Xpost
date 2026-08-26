<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CredentialType;
use App\Enums\TargetStatus;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\XAccount;
use App\Models\XApp;
use App\Services\Publishing\PostPublisher;
use App\Services\X\XRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The video_website card path.
 *
 * A card is the only way to publish a video with the tappable "From <domain>"
 * row under it. It is built through the Ads API and then attached to an
 * ordinary POST /2/tweets, which is what keeps the post on the timeline -- the
 * Ads API's own tweet endpoint refuses nullcast=false outright.
 *
 * Two details cost hours to find and are pinned here:
 *  - the Ads API returns `card://1234` but /2/tweets wants the bare `1234`
 *  - a card supplies its own media, so `media` must not be sent alongside it
 */
class VideoWebsiteCardTest extends TestCase
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
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
        ]);

        $this->flushLimiterKeys();
    }

    protected function tearDown(): void
    {
        $this->flushLimiterKeys();

        parent::tearDown();
    }

    private function flushLimiterKeys(): void
    {
        try {
            $redis = app('redis')->connection();

            foreach ($redis->keys('xpost:rl:*') as $key) {
                $redis->del(str_replace(config('database.redis.options.prefix', ''), '', $key));
            }
        } catch (\Throwable) {
            // No Redis here; the limiter tests cover that case.
        }
    }

    /** An account able to build cards: ads account, OAuth 1.0a, token secret. */
    private function adsAccount(array $overrides = []): XAccount
    {
        return XAccount::factory()->create(array_merge([
            'team_id' => $this->team->id,
            'x_app_id' => $this->xApp->id,
            'ads_account_id' => '18ce55wllne',
            'credential_type' => CredentialType::OAuth1,
            'access_token' => 'token',
            'token_secret' => 'secret',
        ], $overrides));
    }

    private function video(): PostMedia
    {
        return PostMedia::factory()->create([
            'team_id' => $this->team->id,
            'mime' => 'video/mp4',
            // Pre-cached so the uploader never reaches for the file on disk.
            'x_media_ids' => ['amplify:'.$this->xApp->id => '13_999'],
        ]);
    }

    private function target(XAccount $account, array $attributes): PostTarget
    {
        $post = Post::factory()->create(['team_id' => $this->team->id]);

        return PostTarget::factory()->create(array_merge([
            'post_id' => $post->id,
            'team_id' => $this->team->id,
            'x_account_id' => $account->id,
            'status' => TargetStatus::Queued,
        ], $attributes));
    }

    private function fakeX(): void
    {
        Http::fake([
            'ads-api.x.com/*' => Http::response(['data' => ['card_uri' => 'card://5551234']], 201),
            'api.x.com/2/tweets' => Http::response(['data' => ['id' => '777']], 201),
        ]);
    }

    /** @return array the JSON body sent to POST /2/tweets */
    private function publish(PostTarget $target): array
    {
        app(PostPublisher::class)->publish($target->fresh());

        $body = [];

        Http::assertSent(function ($request) use (&$body) {
            if (str_contains($request->url(), 'api.x.com/2/tweets')) {
                $body = $request->data();
            }

            return true;
        });

        return $body;
    }

    #[Test]
    public function a_video_with_a_destination_publishes_as_a_card(): void
    {
        $this->fakeX();
        $media = $this->video();

        $body = $this->publish($this->target($this->adsAccount(), [
            'rendered_body' => 'Xem clip',
            'rendered_url' => 'https://twvideo.top/',
            'rendered_headline' => '54:45',
            'rendered_media_ids' => [$media->id],
        ]));

        // Bare id, not the card:// form the Ads API handed back.
        $this->assertSame('5551234', $body['card_uri']);

        // The card carries the video and the link; sending either again is
        // rejected by X, and a URL left in the text shows as a stray t.co.
        $this->assertArrayNotHasKey('media', $body);
        $this->assertSame('Xem clip', $body['text']);
    }

    #[Test]
    public function the_card_request_carries_the_media_key_and_destination(): void
    {
        $this->fakeX();
        $media = $this->video();

        $this->publish($this->target($this->adsAccount(), [
            'rendered_body' => 'Xem clip',
            'rendered_url' => 'https://twvideo.top/',
            'rendered_headline' => '54:45',
            'rendered_media_ids' => [$media->id],
        ]));

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'ads-api.x.com')) {
                return false;
            }

            $components = collect($request->data()['components'] ?? [])->keyBy('type');

            return $components['MEDIA']['media_key'] === '13_999'
                && $components['DETAILS']['title'] === '54:45'
                && $components['DETAILS']['destination']['url'] === 'https://twvideo.top/';
        });
    }

    #[Test]
    public function card_only_fails_an_account_that_cannot_build_one(): void
    {
        /*
         * The point of card_only is that a campaign renders the same wherever
         * it appears. Publishing a call-to-action version instead would break
         * that invisibly, on whichever accounts happen to lack an ads account
         * -- so the target fails and shows up in the failed column.
         */
        $this->fakeX();
        $media = $this->video();

        $target = $this->target($this->adsAccount(['ads_account_id' => null]), [
            'rendered_body' => 'Xem clip',
            'rendered_url' => 'https://twvideo.top/',
            'rendered_media_ids' => [$media->id],
        ]);

        $outcome = app(PostPublisher::class)->publish($target->fresh());

        $this->assertTrue($outcome->isFailed());
        $this->assertSame('card_unavailable', $target->fresh()->error_code);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.x.com/2/tweets'));
    }

    #[Test]
    public function a_refused_card_hands_its_rate_limit_slots_back(): void
    {
        // Nothing was spent at X, so nothing should be spent here: a slot
        // burned on a post that never went out is one another account needed.
        $this->fakeX();
        $media = $this->video();
        $account = $this->adsAccount(['ads_account_id' => null, 'daily_post_cap' => 50]);

        app(PostPublisher::class)->publish($this->target($account, [
            'rendered_body' => 'Xem clip',
            'rendered_url' => 'https://twvideo.top/',
            'rendered_media_ids' => [$media->id],
        ])->fresh());

        $this->assertSame(50, app(XRateLimiter::class)->remainingForAccount($account));
    }

    #[Test]
    public function card_only_leaves_a_post_without_media_alone(): void
    {
        // There is nothing to build a card from, and refusing to publish a
        // plain link post would help nobody.
        $this->fakeX();

        $body = $this->publish($this->target($this->adsAccount(['ads_account_id' => null]), [
            'rendered_body' => 'Chỉ có link',
            'rendered_url' => 'https://twvideo.top/',
            'rendered_media_ids' => [],
        ]));

        $this->assertSame('Chỉ có link https://twvideo.top/', $body['text']);
    }

    #[Test]
    public function an_account_without_an_ads_account_falls_back_to_a_call_to_action(): void
    {
        // The fallback belongs to `auto`; card_only fails instead, below.
        config(['xpost.publishing.destination_render' => 'auto']);

        $this->fakeX();
        $media = $this->video();
        $media->forceFill(['x_media_ids' => [$this->xApp->id => '999']])->save();

        $body = $this->publish($this->target($this->adsAccount(['ads_account_id' => null]), [
            'rendered_body' => 'Xem clip',
            'rendered_url' => 'https://twvideo.top/',
            'rendered_media_ids' => [$media->id],
        ]));

        // Worse looking, not broken: a button over the video instead of a caption.
        $this->assertArrayNotHasKey('card_uri', $body);
        $this->assertSame('https://twvideo.top/', $body['media']['call_to_actions']['visit_site']['url']);
    }

    #[Test]
    public function an_oauth2_account_cannot_build_cards(): void
    {
        // The Ads API is OAuth 1.0a only; a bearer token gets a bare 403 there.
        config(['xpost.publishing.destination_render' => 'auto']);

        $this->fakeX();
        $media = $this->video();
        $media->forceFill(['x_media_ids' => [$this->xApp->id => '999']])->save();

        $account = $this->adsAccount([
            'credential_type' => CredentialType::OAuth2,
            'token_secret' => null,
            'scopes' => ['tweet.write', 'media.write'],
        ]);

        $body = $this->publish($this->target($account, [
            'rendered_body' => 'Xem clip',
            'rendered_url' => 'https://twvideo.top/',
            'rendered_media_ids' => [$media->id],
        ]));

        $this->assertArrayNotHasKey('card_uri', $body);
    }

    #[Test]
    public function an_image_post_does_not_use_a_card(): void
    {
        // An image cannot make a card, so card_only must not block it either.
        $this->fakeX();

        $image = PostMedia::factory()->create([
            'team_id' => $this->team->id,
            'mime' => 'image/jpeg',
            'x_media_ids' => [$this->xApp->id => '888'],
        ]);

        $body = $this->publish($this->target($this->adsAccount(), [
            'rendered_body' => 'Ảnh thôi',
            'rendered_url' => 'https://twvideo.top/',
            'rendered_media_ids' => [$image->id],
        ]));

        // video_website is a video format; an image gains nothing from it.
        $this->assertArrayNotHasKey('card_uri', $body);
        $this->assertSame(['888'], $body['media']['media_ids']);
    }

    #[Test]
    public function a_failed_card_build_still_publishes_the_post(): void
    {
        config(['xpost.publishing.destination_render' => 'auto']);

        $media = $this->video();
        $media->forceFill([
            'x_media_ids' => ['amplify:'.$this->xApp->id => '13_999', $this->xApp->id => '999'],
        ])->save();

        Http::fake([
            'ads-api.x.com/*' => Http::response(['errors' => [['message' => 'nope']]], 500),
            'api.x.com/2/tweets' => Http::response(['data' => ['id' => '777']], 201),
        ]);

        $body = $this->publish($this->target($this->adsAccount(), [
            'rendered_body' => 'Xem clip',
            'rendered_url' => 'https://twvideo.top/',
            'rendered_media_ids' => [$media->id],
        ]));

        // A card is an upgrade, not a requirement -- losing it must not lose
        // the post.
        $this->assertArrayNotHasKey('card_uri', $body);
        $this->assertSame(['999'], $body['media']['media_ids']);
    }
}
