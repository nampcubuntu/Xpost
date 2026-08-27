<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TargetStatus;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\XAccount;
use App\Models\XApp;
use App\Services\Publishing\PostPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The website destination has to reach X, and there are two ways it can.
 *
 * `rendered_url` was written at fan-out and never read, so every post with a
 * Website destination published as bare text. Nothing failed: the tweet went
 * out, the target went green, and the link was simply missing.
 *
 * The fix is not one line, because X takes the link in two different places.
 * With media attached it belongs in media.call_to_actions -- the dedicated
 * field that renders the tappable "From <domain>" row under the video. With no
 * media there is nothing to hang a CTA on, so it goes in the text and X builds
 * a card from the destination's meta tags. Using the wrong one loses the link.
 */
class WebsiteDestinationTest extends TestCase
{
    use RefreshDatabase;

    private $team;

    private XAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team] = $this->tenant();

        $app = XApp::factory()->create(['team_id' => $this->team->id]);

        $this->account = XAccount::factory()->create([
            'team_id' => $this->team->id,
            'x_app_id' => $app->id,
            'token_expires_at' => now()->addHours(2),
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

    private function target(array $attributes): PostTarget
    {
        $post = Post::factory()->create(['team_id' => $this->team->id]);

        return PostTarget::factory()->create(array_merge([
            'post_id' => $post->id,
            'team_id' => $this->team->id,
            'x_account_id' => $this->account->id,
            'status' => TargetStatus::Queued,
        ], $attributes));
    }

    /**
     * A media row already uploaded to X, so the publisher does not re-upload.
     *
     * Keyed by account: X scopes an upload to whoever made it, so a second
     * account on the same app cannot attach the first one's media.
     */
    private function uploadedMedia(): PostMedia
    {
        return PostMedia::factory()->create([
            'team_id' => $this->team->id,
            'x_media_ids' => ['media:account:'.$this->account->id => '999888777'],
        ]);
    }

    /** @return array the JSON body X received */
    private function publishAndCaptureBody(PostTarget $target): array
    {
        Http::fake(['api.x.com/2/tweets' => Http::response(['data' => ['id' => '1']], 201)]);

        app(PostPublisher::class)->publish($target->fresh());

        $body = [];

        Http::assertSent(function ($request) use (&$body) {
            if (str_contains($request->url(), '/2/tweets')) {
                $body = $request->data();
            }

            return true;
        });

        return $body;
    }

    // ── With media: the call to action ──────────────────────────────────────

    #[Test]
    public function media_carries_the_link_as_a_call_to_action(): void
    {
        // Pinned rather than left to the environment: the CTA is configurable,
        // and a test that reads .env passes or fails by accident.
        config(['xpost.publishing.media_cta' => 'visit_site']);

        $media = $this->uploadedMedia();

        $body = $this->publishAndCaptureBody($this->target([
            'rendered_body' => 'Xem hàng mới về',
            'rendered_url' => 'https://twvideo.top/',
            'rendered_headline' => 'Hàng mới',
            'rendered_media_ids' => [$media->id],
        ]));

        $this->assertSame('https://twvideo.top/', $body['media']['call_to_actions']['visit_site']['url']);
        $this->assertSame('Hàng mới', $body['media']['title']);

        // The URL must not also be pasted into the text -- that would leave a
        // raw t.co link sitting above the button.
        $this->assertSame('Xem hàng mới về', $body['text']);
    }

    #[Test]
    public function the_call_to_action_type_is_configurable(): void
    {
        config(['xpost.publishing.media_cta' => 'watch_now']);

        $media = $this->uploadedMedia();

        $body = $this->publishAndCaptureBody($this->target([
            'rendered_body' => 'Xem clip',
            'rendered_url' => 'https://twvideo.top/',
            'rendered_media_ids' => [$media->id],
        ]));

        $this->assertSame('https://twvideo.top/', $body['media']['call_to_actions']['watch_now']['url']);
        $this->assertArrayNotHasKey('visit_site', $body['media']['call_to_actions']);
    }

    #[Test]
    public function an_unknown_call_to_action_falls_back_rather_than_dropping_the_link(): void
    {
        // A typo in the env must not silently publish a post with no link at all.
        config(['xpost.publishing.media_cta' => 'nonsense']);

        $media = $this->uploadedMedia();

        $body = $this->publishAndCaptureBody($this->target([
            'rendered_body' => 'Xem clip',
            'rendered_url' => 'https://twvideo.top/',
            'rendered_media_ids' => [$media->id],
        ]));

        $this->assertSame('https://twvideo.top/', $body['media']['call_to_actions']['visit_site']['url']);
    }

    #[Test]
    public function the_headline_is_dropped_when_there_is_no_link_to_hang_it_on(): void
    {
        $media = $this->uploadedMedia();

        $body = $this->publishAndCaptureBody($this->target([
            'rendered_body' => 'Chỉ có video',
            'rendered_url' => null,
            'rendered_headline' => 'Tiêu đề mồ côi',
            'rendered_media_ids' => [$media->id],
        ]));

        $this->assertArrayNotHasKey('call_to_actions', $body['media']);
        $this->assertArrayNotHasKey('title', $body['media']);
    }

    // ── Without media: the link goes in the text ────────────────────────────

    #[Test]
    public function a_post_with_no_media_appends_the_link_to_the_text(): void
    {
        $body = $this->publishAndCaptureBody($this->target([
            'rendered_body' => 'Xem hàng mới về',
            'rendered_url' => 'https://twvideo.top/',
            'rendered_media_ids' => [],
        ]));

        $this->assertSame('Xem hàng mới về https://twvideo.top/', $body['text']);
        $this->assertArrayNotHasKey('media', $body);
    }

    #[Test]
    public function a_url_already_in_the_body_is_not_repeated(): void
    {
        // Someone typed the link into the text and also picked it as the
        // destination; posting it twice looks like a mistake, because it is.
        $body = $this->publishAndCaptureBody($this->target([
            'rendered_body' => 'Mua ở https://twvideo.top/ nhé',
            'rendered_url' => 'https://twvideo.top/',
            'rendered_media_ids' => [],
        ]));

        $this->assertSame('Mua ở https://twvideo.top/ nhé', $body['text']);
    }

    #[Test]
    public function a_post_without_a_destination_is_unchanged(): void
    {
        $body = $this->publishAndCaptureBody($this->target([
            'rendered_body' => 'Không có link',
            'rendered_url' => null,
            'rendered_media_ids' => [],
        ]));

        $this->assertSame('Không có link', $body['text']);
    }
}
