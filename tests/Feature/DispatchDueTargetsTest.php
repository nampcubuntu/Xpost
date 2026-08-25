<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TargetStatus;
use App\Jobs\PublishPostTarget;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\XAccount;
use App\Models\XApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DispatchDueTargetsTest extends TestCase
{
    use RefreshDatabase;

    private $team;

    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team] = $this->tenant();

        $app = XApp::factory()->create(['team_id' => $this->team->id]);

        $this->accountIds = XAccount::factory()
            ->count(10)
            ->create(['team_id' => $this->team->id, 'x_app_id' => $app->id])
            ->pluck('id')
            ->all();

        $this->post = Post::factory()->scheduled()->create(['team_id' => $this->team->id]);
    }

    private array $accountIds = [];

    private int $nextAccount = 0;

    /**
     * Accounts are handed out in order rather than at random: post_targets has a
     * unique (post_id, x_account_id) index, so a random pick collides as soon as
     * a test creates more than a couple of targets.
     */
    private function target(array $attributes = []): PostTarget
    {
        return PostTarget::factory()->create(array_merge([
            'post_id' => $this->post->id,
            'team_id' => $this->team->id,
            'x_account_id' => $this->accountIds[$this->nextAccount++ % count($this->accountIds)],
            'scheduled_at' => now()->subMinute(),
            'status' => TargetStatus::Pending,
        ], $attributes));
    }

    #[Test]
    public function it_queues_due_targets(): void
    {
        Queue::fake();

        $this->target();
        $this->target();

        $this->artisan('xpost:dispatch')->assertSuccessful();

        Queue::assertPushed(PublishPostTarget::class, 2);
    }

    #[Test]
    public function it_marks_claimed_targets_as_queued(): void
    {
        Queue::fake();

        $target = $this->target();

        $this->artisan('xpost:dispatch')->assertSuccessful();

        $target->refresh();

        $this->assertSame(TargetStatus::Queued, $target->status);
        $this->assertNotNull($target->claimed_at);
        $this->assertNotNull($target->claimed_by);
    }

    #[Test]
    public function it_ignores_targets_scheduled_for_the_future(): void
    {
        Queue::fake();

        $this->target(['scheduled_at' => now()->addHour()]);

        $this->artisan('xpost:dispatch')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_respects_the_available_at_backoff(): void
    {
        Queue::fake();

        // A target that failed and was deferred must not be picked up early.
        $this->target(['available_at' => now()->addMinutes(30)]);

        $this->artisan('xpost:dispatch')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_does_not_reclaim_targets_already_in_flight(): void
    {
        Queue::fake();

        $this->target(['status' => TargetStatus::Queued]);
        $this->target(['status' => TargetStatus::Publishing]);
        $this->target(['status' => TargetStatus::Published]);

        $this->artisan('xpost:dispatch')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_honours_the_limit_option(): void
    {
        Queue::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->target();
        }

        $this->artisan('xpost:dispatch', ['--limit' => 2])->assertSuccessful();

        Queue::assertPushed(PublishPostTarget::class, 2);
    }

    #[Test]
    public function the_reaper_returns_abandoned_claims_to_the_pool(): void
    {
        $stuck = $this->target([
            'status' => TargetStatus::Publishing,
            'claimed_at' => now()->subHour(),
            'claimed_by' => 'dead-worker:1',
            'attempts' => 1,
        ]);

        $this->artisan('xpost:reap', ['--minutes' => 15])->assertSuccessful();

        $stuck->refresh();

        $this->assertSame(TargetStatus::Pending, $stuck->status);
        $this->assertNull($stuck->claimed_at);
        $this->assertSame('reclaimed', $stuck->error_code);
    }

    #[Test]
    public function the_reaper_gives_up_on_targets_past_the_retry_budget(): void
    {
        $exhausted = $this->target([
            'status' => TargetStatus::Queued,
            'claimed_at' => now()->subHour(),
            'attempts' => 5,
        ]);

        $this->artisan('xpost:reap', ['--minutes' => 15, '--max-attempts' => 5])->assertSuccessful();

        $exhausted->refresh();

        $this->assertSame(TargetStatus::Failed, $exhausted->status);
        $this->assertSame('abandoned', $exhausted->error_code);
    }

    #[Test]
    public function a_recent_claim_is_left_alone(): void
    {
        $working = $this->target([
            'status' => TargetStatus::Publishing,
            'claimed_at' => now()->subMinute(),
        ]);

        $this->artisan('xpost:reap', ['--minutes' => 15])->assertSuccessful();

        $this->assertSame(TargetStatus::Publishing, $working->fresh()->status);
    }
}
