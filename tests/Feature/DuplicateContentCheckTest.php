<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TargetStatus;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\XAccount;
use App\Services\Publishing\DuplicateContentCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * How much of this content has already gone out, before it goes out again.
 *
 * X's rules name duplicative or substantially similar content across accounts
 * you control as never allowed -- with or without automation, staggered or not
 * -- and the stated enforcement reaches every account involved.
 *
 * This hides nothing and blocks nothing. It puts the number in front of the
 * person deciding, on the side of the decision where it is still one.
 */
class DuplicateContentCheckTest extends TestCase
{
    use RefreshDatabase;

    private $team;

    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team, $this->user] = $this->tenant();
    }

    private function publishedPost(PostMedia $media, string $url, int $accounts, ?string $when = null): Post
    {
        $post = Post::factory()->create([
            'team_id' => $this->team->id,
            'website_url' => $url,
            'created_at' => $when ?? now(),
        ]);

        $post->media()->attach($media->id);

        for ($i = 0; $i < $accounts; $i++) {
            PostTarget::factory()->create([
                'post_id' => $post->id,
                'team_id' => $this->team->id,
                'x_account_id' => XAccount::factory()->create(['team_id' => $this->team->id])->id,
                'status' => TargetStatus::Published,
                'published_at' => $when ?? now(),
            ]);
        }

        return $post;
    }

    private function video(): PostMedia
    {
        return PostMedia::factory()->create(['team_id' => $this->team->id, 'mime' => 'video/mp4']);
    }

    #[Test]
    public function it_counts_the_accounts_this_content_already_reached(): void
    {
        $media = $this->video();
        $this->publishedPost($media, 'https://twvideo.top/', 40);

        $result = app(DuplicateContentCheck::class)->for([$media->id], 'https://twvideo.top/');

        $this->assertSame(40, $result['accounts']);
        $this->assertSame(1, $result['posts']);
    }

    #[Test]
    public function the_same_account_across_campaigns_counts_once(): void
    {
        /*
         * One account that has seen this three times is one account, not
         * three. Counting targets would overstate the reach that matters.
         */
        $media = $this->video();
        $account = XAccount::factory()->create(['team_id' => $this->team->id]);

        foreach (range(1, 3) as $ignored) {
            $post = Post::factory()->create([
                'team_id' => $this->team->id,
                'website_url' => 'https://twvideo.top/',
            ]);
            $post->media()->attach($media->id);

            PostTarget::factory()->create([
                'post_id' => $post->id,
                'team_id' => $this->team->id,
                'x_account_id' => $account->id,
                'status' => TargetStatus::Published,
                'published_at' => now(),
            ]);
        }

        $result = app(DuplicateContentCheck::class)->for([$media->id], 'https://twvideo.top/');

        $this->assertSame(1, $result['accounts']);
        $this->assertSame(3, $result['posts']);
    }

    #[Test]
    public function a_campaign_that_never_went_out_does_not_count(): void
    {
        // A cancelled or failed target put nothing in front of anyone.
        $media = $this->video();

        $post = Post::factory()->create(['team_id' => $this->team->id, 'website_url' => 'https://twvideo.top/']);
        $post->media()->attach($media->id);

        PostTarget::factory()->create([
            'post_id' => $post->id,
            'team_id' => $this->team->id,
            'x_account_id' => XAccount::factory()->create(['team_id' => $this->team->id])->id,
            'status' => TargetStatus::Cancelled,
        ]);

        $this->assertSame(0, app(DuplicateContentCheck::class)->for([$media->id], 'https://twvideo.top/')['accounts']);
    }

    #[Test]
    public function a_different_destination_is_different_content(): void
    {
        // Same video pointing somewhere else is a different post, not a repeat.
        $media = $this->video();
        $this->publishedPost($media, 'https://twvideo.top/', 40);

        $this->assertSame(0, app(DuplicateContentCheck::class)->for([$media->id], 'https://elsewhere.com/')['accounts']);
    }

    #[Test]
    public function the_post_being_edited_is_not_counted_against_itself(): void
    {
        $media = $this->video();
        $post = $this->publishedPost($media, 'https://twvideo.top/', 40);

        $result = app(DuplicateContentCheck::class)->for([$media->id], 'https://twvideo.top/', $post->id);

        $this->assertSame(0, $result['accounts']);
    }

    #[Test]
    public function anything_older_than_the_window_is_left_out(): void
    {
        $media = $this->video();
        $this->publishedPost($media, 'https://twvideo.top/', 40, now()->subDays(60)->toDateTimeString());

        $this->assertSame(0, app(DuplicateContentCheck::class)->for([$media->id], 'https://twvideo.top/')['accounts']);
    }

    #[Test]
    public function a_post_with_neither_media_nor_destination_asks_nothing(): void
    {
        // Plain text varies per account through spintax; there is nothing here
        // to match on, and matching on body would flag every campaign.
        $this->assertSame(0, app(DuplicateContentCheck::class)->for([], null)['accounts']);
    }

    #[Test]
    public function the_composer_reports_it_while_the_post_is_still_being_written(): void
    {
        $media = $this->video();
        $this->publishedPost($media, 'https://twvideo.top/', 40);

        $this->actingAs($this->user)
            ->postJson('/api/v1/composer/estimate', [
                'audience' => ['mode' => 'all'],
                'media_ids' => [$media->id],
                'website_url' => 'https://twvideo.top/',
            ])
            ->assertOk()
            ->assertJsonPath('duplicate.accounts', 40);
    }

    #[Test]
    public function another_teams_campaigns_are_invisible(): void
    {
        // The question is what this operator has already sent, not what
        // anyone else has.
        $media = $this->video();
        $this->publishedPost($media, 'https://twvideo.top/', 40);

        [, $other] = $this->tenant();

        $this->actingAs($other)
            ->postJson('/api/v1/composer/estimate', [
                'audience' => ['mode' => 'all'],
                'media_ids' => [$media->id],
                'website_url' => 'https://twvideo.top/',
            ])
            ->assertOk()
            ->assertJsonPath('duplicate.accounts', 0);
    }
}
