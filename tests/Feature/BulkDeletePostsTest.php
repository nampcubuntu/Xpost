<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\Team;
use App\Models\XAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Clearing a selection out of the list.
 *
 * One at a time meant the same confirm dialog forty times, which is how people
 * learn to click through confirms without reading them -- and this one carries
 * the fact that deleting here never reaches a timeline.
 */
class BulkDeletePostsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team, $this->user] = $this->tenant();
        $this->actingAs($this->user->refresh());
    }

    private function posts(int $count): array
    {
        return collect(range(1, $count))
            ->map(fn () => Post::factory()->create(['team_id' => $this->team->id])->id)
            ->all();
    }

    #[Test]
    public function a_selection_goes_in_one_request(): void
    {
        $ids = $this->posts(3);

        $this->deleteJson('/api/v1/posts', ['ids' => $ids])
            ->assertOk()
            ->assertJsonPath('deleted', 3);

        $this->assertSame(0, Post::query()->count());
    }

    #[Test]
    public function pending_targets_are_cancelled_before_the_row_goes(): void
    {
        /*
         * Otherwise the queue holds jobs for a post that no longer exists, and
         * each one fails on a lookup rather than on anything to do with X.
         */
        $post = Post::factory()->create(['team_id' => $this->team->id, 'status' => PostStatus::Scheduled]);
        // One target per account: post_targets is unique on the pair, which is
        // what stops a fan-out sending the same post to an account twice.
        foreach (XAccount::factory()->count(2)->create(['team_id' => $this->team->id]) as $account) {
            PostTarget::factory()->create([
                'post_id' => $post->id,
                'team_id' => $this->team->id,
                'x_account_id' => $account->id,
                'status' => TargetStatus::Pending,
            ]);
        }

        $this->deleteJson('/api/v1/posts', ['ids' => [$post->id]])->assertOk();

        $this->assertSame(0, PostTarget::query()->where('post_id', $post->id)->where('status', TargetStatus::Pending)->count());
    }

    #[Test]
    public function the_reply_counts_how_many_tweets_stay_on_x(): void
    {
        /*
         * Deleting here never reaches a timeline. The count is the only place
         * that says so, and it is said once for the whole selection rather than
         * once per row.
         */
        $post = Post::factory()->create([
            'team_id' => $this->team->id,
            'status' => PostStatus::Published,
            'targets_published' => 12,
        ]);

        $this->deleteJson('/api/v1/posts', ['ids' => [$post->id]])
            ->assertOk()
            ->assertJsonPath('deleted', 1)
            ->assertJsonPath('published', 12);
    }

    #[Test]
    public function another_teams_post_is_left_where_it_is(): void
    {
        // The ids arrive from a browser, so they are resolved through the team
        // scope rather than trusted.
        [$other] = $this->tenant();
        $stranger = Post::factory()->create(['team_id' => $other->id]);

        $this->actingAs($this->user->refresh())
            ->deleteJson('/api/v1/posts', ['ids' => [$stranger->id]])
            ->assertOk()
            ->assertJsonPath('deleted', 0);

        $this->assertNotNull($stranger->fresh());
    }

    #[Test]
    public function a_mixed_selection_deletes_what_it_may_and_reports_it(): void
    {
        // Rather than refusing the whole request over one id that is not the
        // caller's: nine of ten is the answer.
        [$other] = $this->tenant();
        $stranger = Post::factory()->create(['team_id' => $other->id]);

        $this->actingAs($this->user->refresh());
        $mine = $this->posts(2);

        $this->deleteJson('/api/v1/posts', ['ids' => [...$mine, $stranger->id]])
            ->assertOk()
            ->assertJsonPath('deleted', 2);

        $this->assertNotNull($stranger->fresh());
    }

    #[Test]
    public function an_empty_selection_is_refused(): void
    {
        // A bug in the caller, not a no-op worth absorbing.
        $this->deleteJson('/api/v1/posts', ['ids' => []])->assertStatus(422);
    }
}
