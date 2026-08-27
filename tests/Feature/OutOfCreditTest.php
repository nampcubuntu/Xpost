<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TargetStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\XAccount;
use App\Models\XApp;
use App\Services\Publishing\PostPublisher;
use App\Services\Publishing\PublishingHalt;
use App\Services\X\XRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Running out of credit stops publishing, rather than burning the queue.
 *
 * X posting is pay-per-use with no free tier, so a spent balance refuses every
 * account at once. Treated as an ordinary failure it would fail ten thousand
 * targets one at a time -- each burning a rate-limit slot and a claim on a
 * call that cannot succeed until a human tops up.
 */
class OutOfCreditTest extends TestCase
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
            'daily_post_cap' => 50,
        ]);
    }

    private function target(): PostTarget
    {
        $post = Post::factory()->create(['team_id' => $this->team->id]);

        return PostTarget::factory()->create([
            'post_id' => $post->id,
            'team_id' => $this->team->id,
            'x_account_id' => $this->account->id,
            'status' => TargetStatus::Queued,
            'rendered_body' => 'Xin chào',
            'rendered_media_ids' => [],
        ]);
    }

    private function fakeRefusal(int $status, array $body): void
    {
        Http::fake(['api.x.com/2/tweets' => Http::response($body, $status)]);
    }

    #[Test]
    public function a_payment_required_response_halts_publishing(): void
    {
        $this->fakeRefusal(402, ['detail' => 'Your account has insufficient credits.']);

        app(PostPublisher::class)->publish($this->target()->fresh());

        $halt = app(PublishingHalt::class)->current($this->team->id);

        $this->assertNotNull($halt);
        $this->assertStringContainsString('credits', $halt['reason']);
    }

    #[Test]
    public function the_target_is_deferred_rather_than_failed(): void
    {
        /*
         * Nothing is wrong with the post -- failing it would throw away a
         * campaign over a balance a top-up fixes in a minute.
         */
        $this->fakeRefusal(402, ['detail' => 'insufficient credits']);

        $target = $this->target();
        $outcome = app(PostPublisher::class)->publish($target->fresh());

        $this->assertTrue($outcome->isDeferred());
        $this->assertSame(TargetStatus::Pending, $target->fresh()->status);
        $this->assertSame('out_of_credit', $target->fresh()->error_code);
    }

    #[Test]
    public function nothing_is_spent_on_a_post_that_never_went_out(): void
    {
        // The slot was reserved before the call; a slot burned here is one
        // another account needed once the balance is back.
        $this->fakeRefusal(402, ['detail' => 'insufficient credits']);

        app(PostPublisher::class)->publish($this->target()->fresh());

        $this->assertSame(50, app(XRateLimiter::class)->remainingForAccount($this->account));
    }

    #[Test]
    public function a_billing_message_behind_a_429_is_not_mistaken_for_a_rate_limit(): void
    {
        /*
         * Waiting out a window does not add credit. Left as a rate limit the
         * publisher reschedules into the same wall for as long as the queue
         * lasts.
         */
        $this->fakeRefusal(429, ['detail' => 'Usage cap exceeded: please add credits.']);

        $target = $this->target();
        app(PostPublisher::class)->publish($target->fresh());

        $this->assertSame('out_of_credit', $target->fresh()->error_code);
        $this->assertTrue(app(PublishingHalt::class)->isEngaged($this->team->id));
    }

    #[Test]
    public function an_ordinary_rate_limit_still_behaves_as_one(): void
    {
        // The loose prose match must not swallow the common case.
        $this->fakeRefusal(429, ['detail' => 'Too Many Requests']);

        $target = $this->target();
        app(PostPublisher::class)->publish($target->fresh());

        $this->assertSame('x_rate_limited', $target->fresh()->error_code);
        $this->assertFalse(app(PublishingHalt::class)->isEngaged($this->team->id));
    }

    #[Test]
    public function the_dispatcher_holds_targets_back_while_halted(): void
    {
        app(PublishingHalt::class)->engage($this->team->id, 'no credit');

        $target = $this->target();
        $target->forceFill([
            'status' => TargetStatus::Pending,
            'scheduled_at' => now()->subMinute(),
            'available_at' => null,
        ])->save();

        $this->artisan('xpost:dispatch')->assertSuccessful();

        // Still claimable, and not left marked queued with no job behind it.
        $this->assertSame(TargetStatus::Pending, $target->fresh()->status);
        $this->assertNull($target->fresh()->claimed_at);
    }

    #[Test]
    public function resuming_lets_the_queue_move_again(): void
    {
        [, $user] = $this->tenant();
        $halt = app(PublishingHalt::class);
        $halt->engage($user->current_team_id, 'no credit');

        $this->actingAs($user)
            ->postJson('/api/v1/publishing/resume')
            ->assertOk()
            ->assertJsonPath('halted', false);

        $this->assertFalse($halt->isEngaged($user->current_team_id));
    }

    #[Test]
    public function the_posts_endpoint_reports_the_halt(): void
    {
        // The posts page is where a stalled queue is noticed.
        [, $user] = $this->tenant();
        app(PublishingHalt::class)->engage($user->current_team_id, 'insufficient credits');

        $this->actingAs($user)
            ->getJson('/api/v1/posts')
            ->assertOk()
            ->assertJsonPath('halt.reason', 'insufficient credits');
    }

    #[Test]
    public function a_halt_expires_on_its_own(): void
    {
        /*
         * An outage that fixes itself should not need a second human to clear
         * a flag -- and a forgotten halt is a silent outage of its own.
         */
        $halt = app(PublishingHalt::class);
        $halt->engage($this->team->id, 'no credit');

        $this->travel(31)->minutes();

        $this->assertFalse($halt->isEngaged($this->team->id));
    }
}
