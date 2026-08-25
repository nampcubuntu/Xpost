<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\TargetStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\XAccount;
use App\Models\XApp;
use App\Services\Publishing\PostPublisher;
use App\Services\Publishing\PublishOutcome;
use App\Services\X\XRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
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

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
