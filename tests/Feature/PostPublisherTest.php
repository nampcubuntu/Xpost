<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\PostStatus;
use App\Enums\ReplySettings;
use App\Enums\TargetStatus;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\XAccount;
use App\Models\XApp;
use App\Services\Publishing\PostFanoutService;
use App\Services\Publishing\PostPublisher;
use App\Services\Publishing\PublishOutcome;
use App\Services\Publishing\SpendGuard;
use App\Services\X\XRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Exercises the publish path against a faked X API.
 *
 * The rate limiter is mocked out rather than hitting Redis: what is under test
 * here is how the publisher reacts to each kind of API answer, and a real
 * limiter would just add a Redis dependency to every assertion.
 */
class PostPublisherTest extends TestCase
{
    use RefreshDatabase;

    private $team;

    private XAccount $account;

    private PostTarget $target;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team] = $this->tenant();

        $app = XApp::factory()->create(['team_id' => $this->team->id]);

        $this->account = XAccount::factory()->create([
            'team_id' => $this->team->id,
            'x_app_id' => $app->id,
            'username' => 'testhandle',
            'token_expires_at' => now()->addHours(2),
        ]);

        $post = Post::factory()->create(['team_id' => $this->team->id]);

        $this->target = PostTarget::factory()->create([
            'post_id' => $post->id,
            'team_id' => $this->team->id,
            'x_account_id' => $this->account->id,
            'rendered_body' => 'Xin chào từ Xpost',
            'status' => TargetStatus::Pending,
        ]);

        $this->allowRateLimiter();
    }

    /** Every reservation succeeds, and no app is ever reported as blocked. */
    private function allowRateLimiter(): void
    {
        $limiter = Mockery::mock(XRateLimiter::class);
        $limiter->shouldReceive('reserveAccountSlot')->andReturn(null);
        $limiter->shouldReceive('releaseAccountSlot')->andReturnNull();
        $limiter->shouldReceive('reserveAppSlot')->andReturn(null);
        $limiter->shouldReceive('appIsBlocked')->andReturn(false);
        $limiter->shouldReceive('syncFromResponse')->andReturnNull();

        $this->instance(XRateLimiter::class, $limiter);
    }

    private function publish(): PublishOutcome
    {
        return app(PostPublisher::class)->publish($this->target->fresh());
    }

    #[Test]
    public function a_successful_response_marks_the_target_published(): void
    {
        Http::fake([
            'api.x.com/2/tweets' => Http::response([
                'data' => ['id' => '1799999999999999999', 'text' => 'Xin chào từ Xpost'],
            ]),
        ]);

        $outcome = $this->publish();

        $this->assertTrue($outcome->isPublished());
        $this->assertSame('1799999999999999999', $outcome->tweetId);

        $this->target->refresh();

        $this->assertSame(TargetStatus::Published, $this->target->status);
        $this->assertSame(
            'https://x.com/testhandle/status/1799999999999999999',
            $this->target->permalink,
        );
        $this->assertNotNull($this->target->published_at);
    }

    #[Test]
    public function a_successful_publish_updates_the_account_and_app_counters(): void
    {
        Http::fake([
            'api.x.com/2/tweets' => Http::response(['data' => ['id' => '1', 'text' => 'ok']]),
        ]);

        $usedBefore = $this->account->app->monthly_posts_used;

        $this->publish();

        $this->assertNotNull($this->account->fresh()->last_posted_at);
        $this->assertSame($usedBefore + 1, $this->account->app->fresh()->monthly_posts_used);
    }

    #[Test]
    public function a_rate_limit_defers_rather_than_fails(): void
    {
        Http::fake([
            'api.x.com/2/tweets' => Http::response(
                ['title' => 'Too Many Requests'],
                429,
                ['x-rate-limit-remaining' => '0', 'x-rate-limit-reset' => (string) now()->addMinutes(5)->timestamp],
            ),
        ]);

        $outcome = $this->publish();

        $this->assertTrue($outcome->isDeferred());
        $this->assertSame('x_rate_limited', $outcome->reason);

        $this->target->refresh();

        // Back in the pool, but not before the window resets.
        $this->assertSame(TargetStatus::Pending, $this->target->status);
        $this->assertTrue($this->target->available_at->isFuture());
        $this->assertSame(1, $this->target->attempts);
    }

    #[Test]
    public function duplicate_content_fails_terminally(): void
    {
        // Error 187 is X refusing an exact repost; retrying can never help.
        Http::fake([
            'api.x.com/2/tweets' => Http::response([
                'errors' => [['code' => 187, 'message' => 'Status is a duplicate.']],
            ], 403),
        ]);

        $outcome = $this->publish();

        $this->assertTrue($outcome->isFailed());
        $this->assertSame('duplicate_content', $outcome->reason);
        $this->assertSame(TargetStatus::Failed, $this->target->fresh()->status);
    }

    #[Test]
    public function a_401_flags_the_account_for_reauthorisation(): void
    {
        Http::fake([
            'api.x.com/2/tweets' => Http::response(['title' => 'Unauthorized'], 401),
        ]);

        $outcome = $this->publish();

        $this->assertTrue($outcome->isFailed());
        $this->assertSame(AccountStatus::NeedsReauth, $this->account->fresh()->status);
    }

    #[Test]
    public function a_server_error_is_retried_with_backoff(): void
    {
        Http::fake([
            'api.x.com/2/tweets' => Http::response(['title' => 'Internal Error'], 500),
        ]);

        $outcome = $this->publish();

        $this->assertTrue($outcome->isDeferred());
        $this->assertGreaterThan(0, $outcome->retryInSeconds);
        $this->assertSame(TargetStatus::Pending, $this->target->fresh()->status);
    }

    #[Test]
    public function a_disabled_account_is_never_contacted(): void
    {
        Http::fake();

        $this->account->forceFill(['status' => AccountStatus::Disabled])->save();

        $outcome = $this->publish();

        $this->assertTrue($outcome->isFailed());
        Http::assertNothingSent();
    }

    #[Test]
    public function repeated_failures_put_the_account_into_cooldown(): void
    {
        Http::fake([
            'api.x.com/2/tweets' => Http::response([
                'errors' => [['code' => 187, 'message' => 'Status is a duplicate.']],
            ], 403),
        ]);

        $this->account->forceFill(['consecutive_failures' => 2])->save();

        $this->publish();

        $account = $this->account->fresh();

        $this->assertSame(3, $account->consecutive_failures);
        $this->assertNotNull($account->cooldown_until);
        $this->assertTrue($account->cooldown_until->isFuture());
    }

    #[Test]
    public function promoted_only_sends_nullcast(): void
    {
        Http::fake([
            'api.x.com/2/tweets' => Http::response(['data' => ['id' => '1', 'text' => 'ok']]),
        ]);

        $this->target->post->forceFill(['promoted_only' => true])->save();

        $this->publish();

        Http::assertSent(fn ($request) => ($request->data()['nullcast'] ?? false) === true);
    }

    #[Test]
    public function the_posts_reply_setting_reaches_the_tweet_body(): void
    {
        /*
         * The last link in the chain. TweetPayload puts the value in the body,
         * and the enum spells it the way the API schema does -- both asserted
         * elsewhere -- but neither says the publisher reads it off the right
         * post. It takes it from $target->post rather than from the target, so
         * a fan-out that lost the relation would publish with X's default and
         * nothing on the row would say so.
         */
        $this->target->post->forceFill(['reply_settings' => ReplySettings::MentionedUsers])->save();

        Http::fake(['api.x.com/2/tweets' => Http::response(['data' => ['id' => '1', 'text' => 'x']])]);

        $this->publish();

        Http::assertSent(fn ($request) => ($request->data()['reply_settings'] ?? null) === 'mentionedUsers');
    }

    #[Test]
    public function the_default_setting_leaves_the_field_out_of_the_body(): void
    {
        // "everyone" is not a value X accepts -- it is what you get by not
        // sending the field, so sending the word would be refused.
        $this->target->post->forceFill(['reply_settings' => ReplySettings::Everyone])->save();

        Http::fake(['api.x.com/2/tweets' => Http::response(['data' => ['id' => '1', 'text' => 'x']])]);

        $this->publish();

        Http::assertSent(fn ($request) => ! array_key_exists('reply_settings', $request->data()));
    }

    #[Test]
    #[DataProvider('stoppedBeforePublishing')]
    public function a_cancelled_or_deleted_post_stops_before_contacting_x_or_reserving_limits(string $change): void
    {
        Http::fake();
        Http::preventStrayRequests();
        $staleTarget = $this->target->fresh()->load('post');

        match ($change) {
            'target_cancelled' => $this->target->forceFill(['status' => TargetStatus::Cancelled])->save(),
            'post_cancelled' => $this->target->post->forceFill(['status' => PostStatus::Cancelled])->save(),
            'post_deleted' => $this->target->post->delete(),
        };

        $limiter = Mockery::mock(XRateLimiter::class);
        $limiter->shouldNotReceive('reserveAccountSlot', 'reserveAppSlot', 'releaseAccountSlot', 'releaseAppSlot');
        $this->instance(XRateLimiter::class, $limiter);
        Redis::shouldReceive('eval')->never();
        Redis::shouldReceive('decrby')->never();

        $outcome = app(PostPublisher::class)->publish($staleTarget);

        $this->assertTrue($outcome->isCancelled());
        $this->assertSame(TargetStatus::Cancelled, $this->target->fresh()->status);
        $this->assertSame(0, $this->target->fresh()->attempts);
        $this->assertNull($this->target->fresh()->x_tweet_id);
        Http::assertNothingSent();
    }

    public static function stoppedBeforePublishing(): array
    {
        return [
            'target cancelled after the worker loaded it' => ['target_cancelled'],
            'post cancelled after the worker loaded it' => ['post_cancelled'],
            'post deleted after the worker loaded it' => ['post_deleted'],
        ];
    }

    #[Test]
    #[DataProvider('uploadFailuresAfterCancellation')]
    public function an_upload_error_after_cancellation_cannot_requeue_or_fail_the_target(string $failure): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('media/cancelled.jpg', 'image bytes');
        $this->account->forceFill(['scopes' => ['tweet.write', 'media.write']])->save();
        $media = PostMedia::factory()->create([
            'team_id' => $this->team->id,
            'disk' => 'local',
            'path' => 'media/cancelled.jpg',
            'size' => strlen('image bytes'),
        ]);
        $this->target->forceFill([
            'status' => TargetStatus::Publishing,
            'rendered_media_ids' => [$media->id],
            'attempts' => 2,
        ])->save();

        $limiter = Mockery::mock(XRateLimiter::class);
        $limiter->shouldReceive('reserveAccountSlot')->once()->andReturn(null);
        $limiter->shouldReceive('reserveAppSlot')->once()->andReturn(null);
        $limiter->shouldReceive('releaseAccountSlot')->once()->andReturnNull();
        $limiter->shouldReceive('releaseAppSlot')->once()->andReturnNull();
        $limiter->shouldReceive('appIsBlocked')->andReturn(false);
        $limiter->shouldReceive('syncFromResponse')->andReturnNull();
        $this->instance(XRateLimiter::class, $limiter);

        $spend = app(SpendGuard::class);
        $spentBefore = $spend->spentCentsToday($this->team->id);
        Http::preventStrayRequests();
        Http::fake([
            'api.x.com/2/media/upload/initialize' => function () use ($failure) {
                app(PostFanoutService::class)->cancel($this->target->post->fresh());

                return match ($failure) {
                    'connection' => throw new ConnectionException('cURL error 28: Operation timed out'),
                    'server' => Http::response(['title' => 'Internal Error'], 500),
                    'rate_limit' => Http::response(['title' => 'Too Many Requests'], 429),
                };
            },
            'api.x.com/2/tweets' => Http::response(['data' => ['id' => 'must-not-publish']], 201),
        ]);

        $outcome = $this->publish();
        $this->target->refresh();

        $this->assertTrue($outcome->isCancelled());
        $this->assertSame(TargetStatus::Cancelled, $this->target->status);
        $this->assertSame(2, $this->target->attempts);
        $this->assertNull($this->target->x_tweet_id);
        $this->assertNull($this->target->error_code);
        $this->assertSame($spentBefore, $spend->spentCentsToday($this->team->id));
        $this->assertSame(0, $this->account->fresh()->consecutive_failures);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/2/tweets'));
    }

    public static function uploadFailuresAfterCancellation(): array
    {
        return [
            'transport exception' => ['connection'],
            'API exception' => ['server'],
            'rate limit exception' => ['rate_limit'],
        ];
    }

    #[Test]
    public function deleting_the_post_during_a_successful_media_upload_stops_before_tweet_creation(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('media/deleted.jpg', 'image bytes');
        $this->account->forceFill(['scopes' => ['tweet.write', 'media.write']])->save();
        $media = PostMedia::factory()->create([
            'team_id' => $this->team->id,
            'disk' => 'local',
            'path' => 'media/deleted.jpg',
            'size' => strlen('image bytes'),
        ]);
        $this->target->forceFill([
            'status' => TargetStatus::Publishing,
            'rendered_media_ids' => [$media->id],
        ])->save();
        app(XRateLimiter::class)->shouldReceive('releaseAppSlot')->once()->andReturnNull();
        $spend = app(SpendGuard::class);
        $spentBefore = $spend->spentCentsToday($this->team->id);
        Http::preventStrayRequests();
        Http::fake([
            'api.x.com/2/media/upload/initialize' => Http::response(['data' => ['id' => '123']]),
            'api.x.com/2/media/upload/123/append' => Http::response([], 204),
            'api.x.com/2/media/upload/123/finalize' => function () {
                $this->target->post->delete();

                return Http::response(['data' => ['processing_info' => ['state' => 'succeeded']]]);
            },
            'api.x.com/2/tweets' => Http::response(['data' => ['id' => 'must-not-publish']], 201),
        ]);

        $this->assertTrue($this->publish()->isCancelled());
        $this->assertSame(TargetStatus::Cancelled, $this->target->fresh()->status);
        $this->assertNull($this->target->fresh()->x_tweet_id);
        $this->assertSame($spentBefore, $spend->spentCentsToday($this->team->id));
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/2/tweets'));
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
