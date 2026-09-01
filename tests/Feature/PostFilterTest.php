<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\Team;
use App\Models\User;
use App\Models\XAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The filter bar on the posts list.
 *
 * Modelled on Ads Manager's: a checkbox panel, an account picker and one
 * dropdown for "which of my posts". The thing worth testing is not that a
 * checkbox ticks -- it is that ticking every box and ticking none ask the same
 * question, because that is what makes opening the panel safe.
 */
class PostFilterTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team, $this->user] = $this->tenant();
        $this->actingAs($this->user->refresh());
    }

    private function draft(array $attributes = []): Post
    {
        return Post::factory()->create($attributes + ['team_id' => $this->team->id]);
    }

    /** @return list<int> */
    private function ids(array $params): array
    {
        return collect($this->getJson('/api/v1/posts?'.http_build_query($params))->assertOk()->json('data'))
            ->pluck('id')->all();
    }

    #[Test]
    public function every_box_ticked_is_the_same_as_none(): void
    {
        /*
         * The panel opens with everything ticked, so this is the state the list
         * is normally in. If a full set narrowed anything, opening the filters
         * and closing them again would silently drop rows.
         */
        $this->draft(['destination_type' => 'website']);
        $this->draft(['destination_type' => 'none']);

        $all = $this->ids([]);

        $this->assertCount(2, $all);
        $this->assertSame($all, $this->ids([
            'destinations' => ['app', 'website', 'none'],
            'media' => ['photo', 'video', 'none'],
        ]));
    }

    #[Test]
    public function a_destination_can_be_taken_out(): void
    {
        $withLink = $this->draft(['destination_type' => 'website']);
        $this->draft(['destination_type' => 'none']);

        $this->assertSame([$withLink->id], $this->ids(['destinations' => ['website', 'app']]));
    }

    #[Test]
    public function media_is_matched_on_what_the_file_is(): void
    {
        /*
         * On the mime prefix rather than the category enum: the category says
         * how the file was uploaded to X (amplify_video, tweet_image), which is
         * a detail of the upload, not what someone filtering a list means.
         */
        $video = $this->draft();
        $video->media()->attach(PostMedia::factory()->create([
            'team_id' => $this->team->id, 'mime' => 'video/mp4',
        ])->id, ['position' => 0]);

        $photo = $this->draft();
        $photo->media()->attach(PostMedia::factory()->create([
            'team_id' => $this->team->id, 'mime' => 'image/jpeg',
        ])->id, ['position' => 0]);

        $bare = $this->draft();

        $this->assertSame([$video->id], $this->ids(['media' => ['video']]));
        $this->assertSame([$photo->id], $this->ids(['media' => ['photo']]));
        $this->assertSame([$bare->id], $this->ids(['media' => ['none']]));
    }

    #[Test]
    public function the_account_picker_reaches_through_the_fan_out(): void
    {
        /*
         * A post here has no single account -- that is the whole difference
         * from Ads Manager, where a post is one tweet. So the filter goes
         * through the targets, and a post reaching an account twice must still
         * come back once.
         */
        $account = XAccount::factory()->create(['team_id' => $this->team->id]);
        $other = XAccount::factory()->create(['team_id' => $this->team->id]);

        $mine = $this->draft();
        PostTarget::factory()->create([
            'post_id' => $mine->id, 'team_id' => $this->team->id, 'x_account_id' => $account->id,
        ]);

        $theirs = $this->draft();
        PostTarget::factory()->create([
            'post_id' => $theirs->id, 'team_id' => $this->team->id, 'x_account_id' => $other->id,
        ]);

        $this->assertSame([$mine->id], $this->ids(['account_id' => $account->id]));
    }

    #[Test]
    public function one_dropdown_answers_which_of_my_posts(): void
    {
        // Promoted and organic are a column, scheduled and drafts a status.
        // They sit behind one control because it is one question.
        $promoted = $this->draft(['promoted_only' => true, 'status' => 'published']);
        $organic = $this->draft(['promoted_only' => false, 'status' => 'published']);
        $draft = $this->draft(['status' => 'draft', 'promoted_only' => false]);
        $scheduled = $this->draft(['status' => 'scheduled', 'promoted_only' => false]);

        $this->assertSame([$promoted->id], $this->ids(['type' => 'promoted']));
        $this->assertSame([$draft->id], $this->ids(['type' => 'drafts']));
        $this->assertSame([$scheduled->id], $this->ids(['type' => 'scheduled']));
        $this->assertContains($organic->id, $this->ids(['type' => 'organic']));
    }

    #[Test]
    public function a_value_the_enum_does_not_know_is_dropped(): void
    {
        // These arrive from the browser as an array of strings, and one that
        // reached whereIn would be a query built from an untrusted list.
        $this->draft(['destination_type' => 'website']);

        $this->assertCount(1, $this->ids(['destinations' => ['website', 'nonsense']]));
        $this->assertCount(1, $this->ids(['media' => ['photo', 'video', 'none', 'nonsense']]));
    }

    #[Test]
    public function the_filters_combine_rather_than_replace_each_other(): void
    {
        // Four controls on one toolbar, and the one thing a user assumes is
        // that they narrow together.
        $account = XAccount::factory()->create(['team_id' => $this->team->id]);

        $wanted = $this->draft(['destination_type' => 'website', 'promoted_only' => true, 'status' => 'published']);
        PostTarget::factory()->create([
            'post_id' => $wanted->id, 'team_id' => $this->team->id, 'x_account_id' => $account->id,
        ]);

        // Same account, wrong destination.
        $wrongDestination = $this->draft(['destination_type' => 'none', 'promoted_only' => true]);
        PostTarget::factory()->create([
            'post_id' => $wrongDestination->id, 'team_id' => $this->team->id, 'x_account_id' => $account->id,
        ]);

        // Right destination, no target on that account.
        $this->draft(['destination_type' => 'website', 'promoted_only' => true]);

        $this->assertSame([$wanted->id], $this->ids([
            'account_id' => $account->id,
            'destinations' => ['website'],
            'type' => 'promoted',
        ]));
    }
}
