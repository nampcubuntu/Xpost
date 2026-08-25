<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\PostStatus;
use App\Enums\ScheduleMode;
use App\Enums\TargetStatus;
use App\Enums\VariantStrategy;
use App\Models\AccountGroup;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PostVariant;
use App\Models\XAccount;
use App\Models\XApp;
use App\Services\Publishing\PostFanoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PostFanoutTest extends TestCase
{
    use RefreshDatabase;

    private function seedAccounts(int $count, array $attributes = []): void
    {
        [$team] = [$this->team];

        $app = XApp::factory()->create(['team_id' => $team->id]);

        XAccount::factory()->count($count)->create(array_merge([
            'team_id' => $team->id,
            'x_app_id' => $app->id,
        ], $attributes));
    }

    private $team;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team] = $this->tenant();
    }

    #[Test]
    public function it_creates_one_target_per_account(): void
    {
        $this->seedAccounts(12);

        $post = Post::factory()->create([
            'team_id' => $this->team->id,
            'audience' => ['mode' => 'all'],
        ]);

        $created = app(PostFanoutService::class)->fanOut($post);

        $this->assertSame(12, $created);
        $this->assertSame(12, PostTarget::where('post_id', $post->id)->count());
        $this->assertSame(PostStatus::Scheduled, $post->fresh()->status);
    }

    #[Test]
    public function it_skips_accounts_that_cannot_publish(): void
    {
        $this->seedAccounts(5);
        $this->seedAccounts(3, ['status' => AccountStatus::Disabled]);
        $this->seedAccounts(2, ['status' => AccountStatus::NeedsReauth]);

        $post = Post::factory()->create(['team_id' => $this->team->id, 'audience' => ['mode' => 'all']]);

        $this->assertSame(5, app(PostFanoutService::class)->fanOut($post));
    }

    #[Test]
    public function running_fanout_twice_does_not_duplicate_targets(): void
    {
        $this->seedAccounts(8);

        $post = Post::factory()->create(['team_id' => $this->team->id, 'audience' => ['mode' => 'all']]);

        $fanout = app(PostFanoutService::class);

        $this->assertSame(8, $fanout->fanOut($post));
        $this->assertSame(0, $fanout->fanOut($post->fresh()));
        $this->assertSame(8, PostTarget::where('post_id', $post->id)->count());
    }

    #[Test]
    public function it_targets_only_the_selected_group(): void
    {
        $this->seedAccounts(10);

        $group = AccountGroup::factory()->create(['team_id' => $this->team->id]);
        $chosen = XAccount::query()->limit(4)->pluck('id');
        $group->accounts()->attach($chosen);

        $post = Post::factory()->create([
            'team_id' => $this->team->id,
            'audience' => ['mode' => 'groups', 'groups' => [$group->id]],
        ]);

        $this->assertSame(4, app(PostFanoutService::class)->fanOut($post));
    }

    #[Test]
    public function spintax_produces_different_bodies_across_accounts(): void
    {
        $this->seedAccounts(30);

        $post = Post::factory()->create([
            'team_id' => $this->team->id,
            'audience' => ['mode' => 'all'],
            'variant_strategy' => VariantStrategy::Spintax,
            'body' => '{Alpha|Beta|Gamma|Delta} {one|two|three|four} {red|blue|green|black}',
        ]);

        app(PostFanoutService::class)->fanOut($post);

        $distinct = PostTarget::where('post_id', $post->id)
            ->pluck('rendered_body')
            ->unique()
            ->count();

        // 64 permutations over 30 accounts: identical text everywhere would be
        // the bug worth catching, so assert on more than a couple of variants.
        $this->assertGreaterThan(5, $distinct);
    }

    #[Test]
    public function round_robin_distributes_variants_evenly(): void
    {
        $this->seedAccounts(9);

        $post = Post::factory()->create([
            'team_id' => $this->team->id,
            'audience' => ['mode' => 'all'],
            'variant_strategy' => VariantStrategy::RoundRobin,
        ]);

        foreach (['A', 'B', 'C'] as $body) {
            PostVariant::factory()->create(['post_id' => $post->id, 'body' => $body]);
        }

        app(PostFanoutService::class)->fanOut($post->fresh());

        $counts = PostTarget::where('post_id', $post->id)
            ->get()
            ->countBy('rendered_body');

        $this->assertCount(3, $counts);
        $this->assertEquals([3, 3, 3], $counts->values()->all());
    }

    #[Test]
    public function drip_mode_spreads_targets_across_the_window(): void
    {
        $this->seedAccounts(10);

        $post = Post::factory()->create([
            'team_id' => $this->team->id,
            'audience' => ['mode' => 'all'],
            'schedule_mode' => ScheduleMode::Drip,
            'scheduled_at' => now()->addMinutes(10),
            'window_ends_at' => now()->addMinutes(100),
        ]);

        app(PostFanoutService::class)->fanOut($post);

        $times = PostTarget::where('post_id', $post->id)
            ->orderBy('scheduled_at')
            ->pluck('scheduled_at');

        $span = $times->first()->diffInMinutes($times->last());

        $this->assertGreaterThan(80, $span);
        $this->assertLessThanOrEqual(90, $span);
    }

    #[Test]
    public function cancelling_stops_pending_targets_but_keeps_published_ones(): void
    {
        $this->seedAccounts(6);

        $post = Post::factory()->create(['team_id' => $this->team->id, 'audience' => ['mode' => 'all']]);
        $fanout = app(PostFanoutService::class);
        $fanout->fanOut($post);

        PostTarget::where('post_id', $post->id)->limit(2)->update([
            'status' => TargetStatus::Published->value,
        ]);

        $cancelled = $fanout->cancel($post->fresh());

        $this->assertSame(4, $cancelled);
        $this->assertSame(2, PostTarget::where('post_id', $post->id)
            ->where('status', TargetStatus::Published->value)->count());
    }

    #[Test]
    public function a_limited_audience_creates_at_most_that_many_targets(): void
    {
        $this->seedAccounts(20);

        $post = Post::factory()->create([
            'team_id' => $this->team->id,
            'audience' => ['mode' => 'all', 'limit' => 7],
        ]);

        $this->assertSame(7, app(PostFanoutService::class)->fanOut($post));
    }
}
