<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TargetStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\XAccount;
use App\Models\XApp;
use App\Services\Publishing\PostPublisher;
use App\Services\Publishing\PublishOutcome;
use App\Services\X\XAppSelector;
use App\Services\X\XRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An account whose app has spent its 24h allowance must borrow a sibling.
 *
 * Measured against 1,000 accounts posting four times a day: with the old
 * behaviour, accounts assigned to a small-cap app were deferred until midnight
 * even though another app in the pool had thousands of slots free, and the day
 * delivered 1,800 of 4,000 posts.
 *
 * Uses the real Redis-backed limiter rather than a mock -- the point of these
 * tests is the interaction between the counters and the app loop.
 */
class AppFailoverTest extends TestCase
{
    use RefreshDatabase;

    private $team;

    private XApp $small;

    private XApp $large;

    private XAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->redisAvailable()) {
            $this->markTestSkipped('Needs a running Redis; the limiter is not mocked here.');
        }

        [$this->team] = $this->tenant();

        $this->small = XApp::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Small',
            'daily_post_cap' => 2,
            'weight' => 5,
        ]);

        $this->large = XApp::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Large',
            'daily_post_cap' => 10_000,
            'weight' => 1,
        ]);

        $this->account = XAccount::factory()->create([
            'team_id' => $this->team->id,
            'x_app_id' => $this->small->id,
            'username' => 'borrower',
            'token_expires_at' => now()->addHours(2),
        ]);

        $this->flushLimiterKeys();

        Http::fake(['api.x.com/*' => Http::response(['data' => ['id' => '1', 'text' => 'ok']])]);
    }

    protected function tearDown(): void
    {
        if ($this->redisAvailable()) {
            $this->flushLimiterKeys();
        }

        parent::tearDown();
    }

    private function redisAvailable(): bool
    {
        try {
            app('redis')->connection()->ping();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function flushLimiterKeys(): void
    {
        $redis = app('redis')->connection();

        foreach ($redis->keys('xpost:rl:*') as $key) {
            // phpredis returns keys already stripped of the prefix; predis does not.
            $redis->del(str_replace(config('database.redis.options.prefix', ''), '', $key));
        }
    }

    private function targetFor(XAccount $account): PostTarget
    {
        $post = Post::factory()->create(['team_id' => $this->team->id]);

        return PostTarget::factory()->create([
            'post_id' => $post->id,
            'team_id' => $this->team->id,
            'x_account_id' => $account->id,
            'rendered_body' => 'xin chào',
            'status' => TargetStatus::Pending,
        ]);
    }

    private function publish(PostTarget $target): PublishOutcome
    {
        return app(PostPublisher::class)->publish($target->fresh());
    }

    #[Test]
    public function it_uses_the_accounts_own_app_while_that_app_has_room(): void
    {
        $target = $this->targetFor($this->account);

        $this->assertTrue($this->publish($target)->isPublished());
        $this->assertSame($this->small->id, $target->fresh()->x_app_id);
    }

    #[Test]
    public function it_borrows_a_sibling_once_the_own_app_is_spent(): void
    {
        // Burn the small app's two slots.
        $this->publish($this->targetFor($this->account));
        $this->publish($this->targetFor($this->account));

        $third = $this->targetFor($this->account);
        $outcome = $this->publish($third);

        $this->assertTrue($outcome->isPublished(), 'third post should have borrowed the large app');
        $this->assertSame($this->large->id, $third->fresh()->x_app_id);
    }

    #[Test]
    public function it_defers_only_when_the_whole_pool_is_spent(): void
    {
        $limiter = app(XRateLimiter::class);

        // Spend both apps outright.
        foreach ([$this->small, $this->large] as $app) {
            $app->forceFill(['daily_post_cap' => 1])->save();
            $limiter->reserveAppSlot($app->fresh());
        }

        $outcome = $this->publish($this->targetFor($this->account));

        $this->assertTrue($outcome->isDeferred());
        $this->assertSame('app_capacity', $outcome->reason);
    }

    #[Test]
    public function a_failed_app_search_does_not_burn_the_accounts_allowance(): void
    {
        $limiter = app(XRateLimiter::class);

        foreach ([$this->small, $this->large] as $app) {
            $app->forceFill(['daily_post_cap' => 1])->save();
            $limiter->reserveAppSlot($app->fresh());
        }

        $this->publish($this->targetFor($this->account));

        // The account still has its whole day: nothing was published for it.
        $this->assertSame(
            $this->account->daily_post_cap,
            $limiter->remainingForAccount($this->account->fresh()),
        );
    }

    #[Test]
    public function the_accounts_own_cap_still_stops_it(): void
    {
        $this->account->forceFill(['daily_post_cap' => 1])->save();

        $this->assertTrue($this->publish($this->targetFor($this->account->fresh()))->isPublished());

        $outcome = $this->publish($this->targetFor($this->account->fresh()));

        $this->assertTrue($outcome->isDeferred());
        $this->assertSame('account_daily_cap', $outcome->reason);
    }

    #[Test]
    public function the_candidate_list_puts_the_owned_app_first(): void
    {
        $candidates = app(XAppSelector::class)->candidatesFor($this->account);

        $this->assertSame($this->small->id, $candidates[0]->id);
        $this->assertContains($this->large->id, array_column($candidates, 'id'));
    }
}
