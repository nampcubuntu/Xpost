<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TargetStatus;
use App\Jobs\PublishPostTarget;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\Team;
use App\Models\User;
use App\Models\XAccount;
use App\Support\TeamContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * No team can take the whole dispatch run.
 *
 * Ordering the queue by scheduled_at alone hands every slot to whichever team
 * has the oldest work, so one team fanning out ten thousand targets stops
 * every other team until it drains. With one tenant that is invisible; with a
 * thousand paying ones it is an outage for the rest of them.
 */
class QueueFairnessTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<int> the ids of the targets created */
    private function targetsFor(Team $team, int $count, int $minutesAgo): array
    {
        return TeamContext::runAs($team->id, function () use ($team, $count, $minutesAgo) {
            $post = Post::factory()->create(['team_id' => $team->id]);

            $ids = [];

            for ($i = 0; $i < $count; $i++) {
                // One target per account: post_targets is unique on the pair.
                $account = XAccount::factory()->create(['team_id' => $team->id]);

                $ids[] = PostTarget::factory()->create([
                    'post_id' => $post->id,
                    'team_id' => $team->id,
                    'x_account_id' => $account->id,
                    'status' => TargetStatus::Pending,
                    // Older than the other team's, so a plain ordering by
                    // scheduled_at would take all of these first.
                    'scheduled_at' => now()->subMinutes($minutesAgo),
                    'available_at' => null,
                ])->id;
            }

            return $ids;
        });
    }

    private function team(string $name): Team
    {
        return Team::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'owner_id' => User::factory()->create()->id,
            'timezone' => 'UTC',
        ]);
    }

    /** @return array<int, int> team id => how many of its targets were queued */
    private function dispatchAndCount(int $limit): array
    {
        Queue::fake();

        $this->artisan('xpost:dispatch', ['--limit' => $limit])->assertSuccessful();

        return PostTarget::withoutGlobalScopes()
            ->where('status', TargetStatus::Queued->value)
            ->get()
            ->groupBy('team_id')
            ->map->count()
            ->all();
    }

    #[Test]
    public function one_team_cannot_take_every_slot(): void
    {
        $hog = $this->team('Hog');
        $quiet = $this->team('Quiet');

        // The greedy team's work is older, so plain ordering would take it all.
        $this->targetsFor($hog, 100, minutesAgo: 60);
        $this->targetsFor($quiet, 10, minutesAgo: 5);

        $counts = $this->dispatchAndCount(20);

        $this->assertSame(10, $counts[$hog->id] ?? 0);
        $this->assertSame(10, $counts[$quiet->id] ?? 0);
    }

    #[Test]
    public function capacity_a_team_cannot_use_goes_to_the_backlog(): void
    {
        /*
         * Without the top-up pass a team with a single due target still holds
         * a full slice, and the run leaves most of its capacity idle while a
         * backlog waits.
         */
        $hog = $this->team('Hog');
        $quiet = $this->team('Quiet');

        $this->targetsFor($hog, 100, minutesAgo: 60);
        $this->targetsFor($quiet, 1, minutesAgo: 5);

        $counts = $this->dispatchAndCount(20);

        $this->assertSame(1, $counts[$quiet->id] ?? 0);
        // The other nineteen are not wasted.
        $this->assertSame(19, $counts[$hog->id] ?? 0);
    }

    #[Test]
    public function a_single_tenant_is_not_throttled_by_the_share(): void
    {
        // Fairness between one team and nobody is just the old behaviour.
        $only = $this->team('Only');
        $this->targetsFor($only, 50, minutesAgo: 10);

        $this->assertSame(20, $this->dispatchAndCount(20)[$only->id] ?? 0);
    }

    #[Test]
    public function ten_teams_get_an_equal_slice(): void
    {
        $teams = [];

        foreach (range(1, 10) as $n) {
            $teams[] = $team = $this->team("Team {$n}");
            $this->targetsFor($team, 50, minutesAgo: $n);
        }

        $counts = $this->dispatchAndCount(100);

        foreach ($teams as $team) {
            $this->assertSame(10, $counts[$team->id] ?? 0, "team {$team->id} did not get its share");
        }
    }

    #[Test]
    public function restricting_to_one_team_still_takes_the_whole_limit(): void
    {
        // --team is for operators draining a single tenant; sharing a run with
        // teams it was told to ignore would defeat the point.
        $one = $this->team('One');
        $other = $this->team('Other');

        $this->targetsFor($one, 50, minutesAgo: 10);
        $this->targetsFor($other, 50, minutesAgo: 10);

        Queue::fake();
        $this->artisan('xpost:dispatch', ['--limit' => 20, '--team' => $one->id])->assertSuccessful();

        $counts = PostTarget::withoutGlobalScopes()
            ->where('status', TargetStatus::Queued->value)
            ->get()->groupBy('team_id')->map->count()->all();

        $this->assertSame(20, $counts[$one->id] ?? 0);
        $this->assertSame(0, $counts[$other->id] ?? 0);
    }

    #[Test]
    public function every_claimed_target_still_gets_a_job(): void
    {
        // The two-query claim must not lose rows between selecting and locking.
        $team = $this->team('Solo');
        $this->targetsFor($team, 5, minutesAgo: 5);

        Queue::fake();
        $this->artisan('xpost:dispatch', ['--limit' => 10])->assertSuccessful();

        Queue::assertPushed(PublishPostTarget::class, 5);
    }
}
