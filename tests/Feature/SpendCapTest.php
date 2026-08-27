<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TargetStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\XAccount;
use App\Models\XApp;
use App\Services\Publishing\PostPublisher;
use App\Services\Publishing\SpendGuard;
use App\Services\X\XRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A ceiling on what one team spends at X in a day.
 *
 * Posting is pay-per-use, and until this the only thing that stopped a team was
 * the balance running out -- which arrives after the money is gone and stops
 * every other team with it. A recurring schedule pointed at ten thousand
 * accounts spends whatever it is given, overnight.
 */
class SpendCapTest extends TestCase
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
            'daily_post_cap' => 500,
        ]);

        $this->flushKeys();
    }

    protected function tearDown(): void
    {
        $this->flushKeys();

        parent::tearDown();
    }

    private function flushKeys(): void
    {
        try {
            $redis = app('redis')->connection();
            $prefix = config('database.redis.options.prefix', '');

            foreach ($redis->keys('xpost:spend:*') as $key) {
                $redis->del(str_replace($prefix, '', $key));
            }

            foreach ($redis->keys('xpost:rl:*') as $key) {
                $redis->del(str_replace($prefix, '', $key));
            }
        } catch (\Throwable) {
            // No Redis here; these tests need it and will say so.
        }
    }

    private function target(?string $url = null): PostTarget
    {
        $post = Post::factory()->create(['team_id' => $this->team->id]);

        return PostTarget::factory()->create([
            'post_id' => $post->id,
            'team_id' => $this->team->id,
            'x_account_id' => XAccount::factory()->create([
                'team_id' => $this->team->id,
                'x_app_id' => $this->account->x_app_id,
            ])->id,
            'status' => TargetStatus::Queued,
            'rendered_body' => 'Xin chào',
            'rendered_url' => $url,
            'rendered_media_ids' => [],
        ]);
    }

    private function fakeX(): void
    {
        Http::fake(['api.x.com/2/tweets' => Http::response(['data' => ['id' => '777']], 201)]);
    }

    #[Test]
    public function a_link_costs_more_than_a_plain_post(): void
    {
        /*
         * By an order of magnitude, and the card path always carries one -- so
         * this is not a rounding difference, it decides whether a day costs
         * sixty dollars or eight hundred.
         */
        $guard = app(SpendGuard::class);

        $this->assertSame(2, $guard->costCentsFor($this->target()));
        $this->assertSame(20, $guard->costCentsFor($this->target('https://twvideo.top/')));
    }

    #[Test]
    public function a_team_without_a_budget_is_not_stopped(): void
    {
        // Null is the default: a limit nobody chose, silently stopping a
        // campaign, would be worse than none.
        $this->fakeX();
        $this->assertNull($this->team->daily_budget_usd);

        $outcome = app(PostPublisher::class)->publish($this->target()->fresh());

        $this->assertTrue($outcome->isPublished());
    }

    #[Test]
    public function publishing_stops_when_the_budget_is_spent(): void
    {
        $this->fakeX();
        // Two link posts at twenty cents; the third is over.
        $this->team->forceFill(['daily_budget_usd' => 0.40])->save();

        $published = 0;
        $deferred = 0;

        foreach (range(1, 3) as $ignored) {
            $outcome = app(PostPublisher::class)->publish($this->target('https://twvideo.top/')->fresh());
            $outcome->isPublished() ? $published++ : $deferred++;
        }

        $this->assertSame(2, $published);
        $this->assertSame(1, $deferred);
    }

    #[Test]
    public function the_stopped_target_is_deferred_rather_than_failed(): void
    {
        // Tomorrow's budget publishes it unchanged; failing would throw away a
        // campaign over a ceiling that resets at midnight.
        $this->fakeX();
        $this->team->forceFill(['daily_budget_usd' => 0.01])->save();

        $target = $this->target('https://twvideo.top/');
        app(PostPublisher::class)->publish($target->fresh());

        $this->assertSame(TargetStatus::Pending, $target->fresh()->status);
        $this->assertSame('team_budget_spent', $target->fresh()->error_code);
    }

    #[Test]
    public function a_post_that_never_went_out_is_refunded(): void
    {
        /*
         * Seven paths leave the publisher without publishing, and every one
         * costs X nothing. Not refunding makes the budget drift down until a
         * team is stopped by money it never spent.
         */
        Http::fake(['api.x.com/2/tweets' => Http::response(['detail' => 'nope'], 400)]);
        $this->team->forceFill(['daily_budget_usd' => 10.00])->save();

        app(PostPublisher::class)->publish($this->target('https://twvideo.top/')->fresh());

        $this->assertSame(0, app(SpendGuard::class)->spentCentsToday($this->team->id));
    }

    #[Test]
    public function a_published_post_is_charged(): void
    {
        $this->fakeX();
        $this->team->forceFill(['daily_budget_usd' => 10.00])->save();

        app(PostPublisher::class)->publish($this->target('https://twvideo.top/')->fresh());

        $this->assertSame(20, app(SpendGuard::class)->spentCentsToday($this->team->id));
    }

    #[Test]
    public function nothing_is_spent_at_the_rate_limiter_either(): void
    {
        // The account and app slots are reserved before the budget check, so
        // a stopped post has to hand them back too.
        $this->fakeX();
        $this->team->forceFill(['daily_budget_usd' => 0.01])->save();

        $target = $this->target('https://twvideo.top/');
        app(PostPublisher::class)->publish($target->fresh());

        $account = XAccount::withoutGlobalScopes()->find($target->x_account_id);

        $this->assertSame(
            $account->daily_post_cap,
            app(XRateLimiter::class)->remainingForAccount($account),
        );
    }

    #[Test]
    public function one_team_budget_does_not_touch_another(): void
    {
        $guard = app(SpendGuard::class);

        $guard->reserve($this->team->id, 100);

        $this->assertSame(100, $guard->spentCentsToday($this->team->id));
        $this->assertSame(0, $guard->spentCentsToday($this->team->id + 1));
    }

    #[Test]
    public function the_counter_expires_at_midnight(): void
    {
        // A budget means "today" the way an invoice does, not a rolling day.
        $guard = app(SpendGuard::class);
        $guard->reserve($this->team->id, 100);

        $ttl = Redis::ttl('xpost:spend:'.$this->team->id.':'.now()->format('Ymd'));

        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(now()->diffInSeconds(now()->endOfDay(), false) + 2, $ttl);
    }
}
