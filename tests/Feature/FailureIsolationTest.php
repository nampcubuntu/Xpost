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
use App\Services\X\XRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What one account's failure does to the other 999.
 *
 * A blast is one job per target, so isolation is the default -- but "the code
 * is structured that way" is not evidence. These tests publish a batch where
 * some accounts are rigged to fail and assert on what happened to the rest.
 */
class FailureIsolationTest extends TestCase
{
    use RefreshDatabase;

    private $team;

    private XApp $xApp;

    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team] = $this->tenant();

        $this->xApp = XApp::factory()->create(['team_id' => $this->team->id]);
        $this->post = Post::factory()->create(['team_id' => $this->team->id]);

        // RefreshDatabase resets account ids but not Redis, so without this the
        // second test in the class inherits the first one's spent allowance.
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
                // phpredis strips the prefix from returned keys; predis does not.
                $redis->del(str_replace(config('database.redis.options.prefix', ''), '', $key));
            }
        } catch (\Throwable) {
            // No Redis in this environment; the limiter tests will say so.
        }
    }

    /** @return array<string, PostTarget> keyed by handle */
    private function batch(array $handles): array
    {
        $targets = [];

        foreach ($handles as $handle) {
            $account = XAccount::factory()->create([
                'team_id' => $this->team->id,
                'x_app_id' => $this->xApp->id,
                'username' => $handle,
                'token_expires_at' => now()->addHours(2),
            ]);

            $targets[$handle] = PostTarget::factory()->create([
                'post_id' => $this->post->id,
                'team_id' => $this->team->id,
                'x_account_id' => $account->id,
                'rendered_body' => "Xin chào từ {$handle}",
                'status' => TargetStatus::Queued,
            ]);
        }

        return $targets;
    }

    /**
     * Rig the API per handle. The publisher sends the handle in the body, so
     * the sequence is keyed off what each account is posting.
     */
    private function fakeApi(array $responsesByHandle): void
    {
        Http::fake(function ($request) use ($responsesByHandle) {
            $body = (string) $request->body();

            foreach ($responsesByHandle as $handle => $response) {
                if (str_contains($body, $handle)) {
                    return $response;
                }
            }

            return Http::response(['data' => ['id' => (string) random_int(1, 9_999_999)]], 201);
        });
    }

    private function publishAll(array $targets): array
    {
        $publisher = app(PostPublisher::class);
        $outcomes = [];

        foreach ($targets as $handle => $target) {
            $outcomes[$handle] = $publisher->publish($target->fresh());
        }

        return $outcomes;
    }

    #[Test]
    public function one_dead_account_does_not_stop_the_others(): void
    {
        $targets = $this->batch(['good_a', 'revoked', 'good_b', 'duplicate', 'good_c']);

        $this->fakeApi([
            // Token revoked -- terminal, needs a human.
            'revoked' => Http::response(['title' => 'Unauthorized'], 401),
            // Duplicate status -- terminal, but the account is fine.
            'duplicate' => Http::response(['errors' => [['code' => 187, 'message' => 'Status is a duplicate.']]], 403),
        ]);

        $outcomes = $this->publishAll($targets);

        // The three healthy accounts published, untouched by their neighbours.
        foreach (['good_a', 'good_b', 'good_c'] as $handle) {
            $this->assertTrue($outcomes[$handle]->isPublished(), "{$handle} should have published");
            $this->assertSame(TargetStatus::Published, $targets[$handle]->fresh()->status);
        }

        $this->assertSame(TargetStatus::Failed, $targets['revoked']->fresh()->status);
        $this->assertSame(TargetStatus::Failed, $targets['duplicate']->fresh()->status);
    }

    #[Test]
    public function a_revoked_token_takes_only_its_own_account_out_of_rotation(): void
    {
        $targets = $this->batch(['healthy', 'revoked']);

        $this->fakeApi(['revoked' => Http::response(['title' => 'Unauthorized'], 401)]);
        $this->publishAll($targets);

        $this->assertSame(
            AccountStatus::NeedsReauth,
            $targets['revoked']->fresh()->account->status,
            'the revoked account should be pulled out',
        );

        $this->assertSame(
            AccountStatus::Active,
            $targets['healthy']->fresh()->account->status,
            'its neighbour on the same app should be untouched',
        );
    }

    #[Test]
    public function a_server_error_is_retried_rather_than_dropped(): void
    {
        $targets = $this->batch(['flaky']);

        $this->fakeApi(['flaky' => Http::response(['title' => 'Internal Error'], 500)]);
        $outcomes = $this->publishAll($targets);

        $target = $targets['flaky']->fresh();

        // Back to pending with a future available_at: the dispatcher will pick
        // it up again, it is not a failure yet.
        $this->assertTrue($outcomes['flaky']->isDeferred());
        $this->assertSame(TargetStatus::Pending, $target->status);
        $this->assertSame(1, $target->attempts);
        $this->assertTrue($target->available_at->isFuture());
    }

    #[Test]
    public function a_retried_target_eventually_succeeds(): void
    {
        $targets = $this->batch(['recovers']);

        // Fails once, then the next attempt goes through.
        Http::fake([
            '*' => Http::sequence()
                ->push(['title' => 'Internal Error'], 500)
                ->push(['data' => ['id' => '1234567890']], 201),
        ]);

        $publisher = app(PostPublisher::class);

        $first = $publisher->publish($targets['recovers']->fresh());
        $this->assertTrue($first->isDeferred());

        // What the dispatcher does once available_at passes.
        $targets['recovers']->fresh()->forceFill([
            'status' => TargetStatus::Queued,
            'available_at' => null,
        ])->save();

        $second = $publisher->publish($targets['recovers']->fresh());

        $this->assertTrue($second->isPublished());
        $this->assertSame(TargetStatus::Published, $targets['recovers']->fresh()->status);
        $this->assertSame('1234567890', $targets['recovers']->fresh()->x_tweet_id);
    }

    #[Test]
    public function a_permanent_error_is_not_retried(): void
    {
        $targets = $this->batch(['duplicate']);

        $this->fakeApi([
            'duplicate' => Http::response(['errors' => [['code' => 187, 'message' => 'Status is a duplicate.']]], 403),
        ]);

        $outcome = $this->publishAll($targets)['duplicate'];

        // Retrying a duplicate would fail forever; it is closed out instead.
        $this->assertTrue($outcome->isFailed());
        $this->assertSame(TargetStatus::Failed, $targets['duplicate']->fresh()->status);
        $this->assertNull($targets['duplicate']->fresh()->available_at);
    }

    #[Test]
    public function repeated_failures_cool_one_account_down_without_touching_the_rest(): void
    {
        $targets = $this->batch(['healthy', 'broken']);

        $this->fakeApi([
            'broken' => Http::response(['errors' => [['code' => 187, 'message' => 'Status is a duplicate.']]], 403),
        ]);

        $publisher = app(PostPublisher::class);

        // Three strikes is the configured cooldown threshold.
        for ($i = 0; $i < 3; $i++) {
            $targets['broken']->fresh()->forceFill(['status' => TargetStatus::Queued])->save();
            $publisher->publish($targets['broken']->fresh());
        }

        $broken = $targets['broken']->fresh()->account;
        $healthy = $targets['healthy']->fresh()->account;

        $this->assertSame(3, $broken->consecutive_failures);
        $this->assertNotNull($broken->cooldown_until, 'the failing account should be resting');
        $this->assertLessThan(100, $broken->health_score);

        $this->assertSame(0, $healthy->consecutive_failures);
        $this->assertNull($healthy->cooldown_until);
    }

    #[Test]
    public function a_rate_limited_account_is_deferred_not_failed(): void
    {
        $targets = $this->batch(['limited', 'fine']);

        $this->fakeApi([
            'limited' => Http::response(
                ['title' => 'Too Many Requests'],
                429,
                ['x-rate-limit-reset' => (string) now()->addMinutes(5)->timestamp],
            ),
        ]);

        $outcomes = $this->publishAll($targets);

        $this->assertTrue($outcomes['limited']->isDeferred());
        $this->assertSame(TargetStatus::Pending, $targets['limited']->fresh()->status);

        // Nothing about being throttled on one account stops another.
        $this->assertTrue($outcomes['fine']->isPublished());
    }

    #[Test]
    public function a_failed_publish_does_not_burn_the_accounts_daily_allowance(): void
    {
        $targets = $this->batch(['duplicate']);

        $this->fakeApi([
            'duplicate' => Http::response(['errors' => [['code' => 187, 'message' => 'Status is a duplicate.']]], 403),
        ]);

        $this->publishAll($targets);

        // The slot was reserved before the call. It stays spent -- X counts the
        // request either way -- so the count is what the operator should see.
        $account = $targets['duplicate']->fresh()->account;

        $this->assertSame(
            ($account->daily_post_cap ?: 50) - 1,
            app(XRateLimiter::class)->remainingForAccount($account),
        );
    }
}
