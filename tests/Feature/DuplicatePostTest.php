<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Enums\ScheduleMode;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Copying a post to run it again.
 *
 * Everything that describes the post is carried. Everything that describes what
 * happened to it is not -- a copy has never been published, and inheriting that
 * history is how a draft ends up claiming an audience it never reached.
 */
class DuplicatePostTest extends TestCase
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

    private function original(array $overrides = []): Post
    {
        $post = Post::factory()->create($overrides + [
            'team_id' => $this->team->id,
            'name' => 'Chiến dịch tháng 8',
            'body' => 'Xin chào {bạn|các bạn}',
            'status' => PostStatus::Published,
            'published_at' => now()->subDay(),
            'scheduled_at' => now()->subDay(),
            'audience' => ['mode' => 'all', 'shuffle' => true, 'limit' => 200],
        ]);

        $post->media()->sync(PostMedia::factory()->count(2)->create(['team_id' => $this->team->id])->pluck('id'));

        return $post->fresh(['media']);
    }

    #[Test]
    public function the_copy_carries_what_describes_the_post(): void
    {
        $post = $this->original();

        $copy = $this->postJson("/api/v1/posts/{$post->id}/duplicate")->assertCreated()->json('data');

        $this->assertSame($post->body, $copy['body']);
        $this->assertSame($post->audience, $copy['audience']);
        $this->assertNotSame($post->id, $copy['id']);
    }

    #[Test]
    public function the_media_comes_with_it(): void
    {
        // Without this the copy is a body with nothing to publish, and the
        // first sign of it is an empty preview.
        $post = $this->original();

        $copy = $this->postJson("/api/v1/posts/{$post->id}/duplicate")->assertCreated()->json('data');

        $this->assertCount(2, $copy['media']);
        $this->assertSame(
            $post->media->pluck('id')->sort()->values()->all(),
            collect($copy['media'])->pluck('id')->sort()->values()->all()
        );
    }

    #[Test]
    public function the_copy_has_not_been_published(): void
    {
        /*
         * The part worth getting right. A copy that inherited Published would
         * show a delivery history it never had, and one that inherited the
         * original's time would either fire the moment it is saved or sit as a
         * scheduled post that can never run.
         */
        $post = $this->original();

        $copy = $this->postJson("/api/v1/posts/{$post->id}/duplicate")->assertCreated()->json('data');

        $this->assertSame(PostStatus::Draft->value, $copy['status']);
        $this->assertNull($copy['published_at']);
        $this->assertNull($copy['scheduled_at']);
        $this->assertSame(ScheduleMode::Immediate->value, $copy['schedule_mode']);
        $this->assertSame(0, $copy['progress']['total']);
    }

    #[Test]
    public function the_copy_is_named_as_one(): void
    {
        // Two rows with the same name in a list is the reason people stop
        // trusting the list.
        $post = $this->original();

        $copy = $this->postJson("/api/v1/posts/{$post->id}/duplicate")->assertCreated()->json('data');

        $this->assertSame('Chiến dịch tháng 8 (bản sao)', $copy['name']);
    }

    #[Test]
    public function the_original_is_left_alone(): void
    {
        $post = $this->original();

        $this->postJson("/api/v1/posts/{$post->id}/duplicate")->assertCreated();

        $this->assertSame(PostStatus::Published, $post->fresh()->status);
        $this->assertCount(2, $post->fresh()->media);
    }

    #[Test]
    public function another_teams_post_cannot_be_copied(): void
    {
        /*
         * Copying is a read of the original before it is a write, and the read
         * is the part that has to be refused. 403 rather than 404 is what
         * PostPolicy already answers across this controller.
         */
        [$other] = $this->tenant();
        $stranger = Post::factory()->create(['team_id' => $other->id]);

        $before = Post::query()->withoutGlobalScopes()->count();

        $this->actingAs($this->user->refresh())
            ->postJson("/api/v1/posts/{$stranger->id}/duplicate")
            ->assertForbidden();

        // Refused before anything was written, not after.
        $this->assertSame($before, Post::query()->withoutGlobalScopes()->count());
    }
}
