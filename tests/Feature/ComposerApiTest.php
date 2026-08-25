<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Jobs\FanOutPost;
use App\Models\Post;
use App\Models\XAccount;
use App\Models\XApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ComposerApiTest extends TestCase
{
    use RefreshDatabase;

    private $team;

    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team, $this->user] = $this->tenant();

        $app = XApp::factory()->create(['team_id' => $this->team->id]);
        XAccount::factory()->count(6)->create(['team_id' => $this->team->id, 'x_app_id' => $app->id]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Chiến dịch thử',
            'body' => 'Xin chào từ Xpost',
            'reply_settings' => 'everyone',
            'destination_type' => 'website',
            'website_url' => 'https://example.com',
            'headline' => 'Tiêu đề',
            'variant_strategy' => 'same',
            'schedule_mode' => 'immediate',
            'audience' => ['mode' => 'all'],
        ], $overrides);
    }

    #[Test]
    public function it_creates_a_draft(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/posts', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Chiến dịch thử')
            ->assertJsonPath('data.status', PostStatus::Draft->value)
            ->assertJsonPath('preview.accounts', 6);
    }

    #[Test]
    public function it_rejects_a_post_with_no_text_and_no_media(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/posts', $this->payload(['body' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }

    #[Test]
    public function it_rejects_text_over_the_weighted_limit(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/posts', $this->payload(['body' => str_repeat('a', 281)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }

    #[Test]
    public function it_counts_a_url_as_twenty_three_characters_not_its_length(): void
    {
        // 250 chars of text plus a very long URL: under a naive strlen check
        // this passes, and X then rejects it. The rule has to weight the URL.
        $this->actingAs($this->user)
            ->postJson('/api/v1/posts', $this->payload([
                'body' => str_repeat('a', 250).' https://example.com/'.str_repeat('b', 200),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }

    #[Test]
    public function it_rejects_unbalanced_spintax(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/posts', $this->payload(['body' => 'Xin {chào|hello world']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }

    #[Test]
    public function a_website_destination_requires_a_url(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/posts', $this->payload(['website_url' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('website_url');
    }

    #[Test]
    public function publishing_queues_the_fanout_job(): void
    {
        Queue::fake();

        $post = Post::factory()->create([
            'team_id' => $this->team->id,
            'audience' => ['mode' => 'all'],
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/posts/{$post->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', PostStatus::Scheduled->value);

        Queue::assertPushed(FanOutPost::class);
    }

    #[Test]
    public function the_measure_endpoint_matches_x_counting_rules(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/composer/measure', ['body' => 'check https://a-very-long-example.com/path'])
            ->assertOk()
            // "check " is 6 and the URL is fixed at 23.
            ->assertJsonPath('weighted_length', 29)
            ->assertJsonPath('remaining', 251);
    }

    #[Test]
    public function a_scheduled_time_is_read_in_the_senders_timezone(): void
    {
        /*
         * The browser sends a bare wall clock. Stored as-is it becomes 20:00 UTC
         * -- 03:00 the next morning for a Vietnamese user, and a different wrong
         * hour for everyone else in a global pool.
         */
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/posts', $this->payload([
                'schedule_mode' => 'fixed_time',
                'timezone' => 'Asia/Ho_Chi_Minh',
                'scheduled_at' => now()->addDay()->setTime(20, 0)->format('Y-m-d\TH:i'),
            ]));

        $response->assertCreated();

        $post = Post::withoutGlobalScopes()->findOrFail($response->json('data.id'));

        $this->assertSame('13:00', $post->scheduled_at->format('H:i'));
        $this->assertSame('20:00', $post->scheduled_at->setTimezone('Asia/Ho_Chi_Minh')->format('H:i'));
    }

    #[Test]
    public function an_explicit_offset_is_converted_rather_than_reinterpreted(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/posts', $this->payload([
                'schedule_mode' => 'fixed_time',
                'timezone' => 'America/New_York',
                'scheduled_at' => now()->addDay()->setTime(20, 0)->format('Y-m-d\TH:i:s').'+07:00',
            ]));

        $response->assertCreated();

        $post = Post::withoutGlobalScopes()->findOrFail($response->json('data.id'));

        // +07:00 is unambiguous, so New York must not be applied on top of it.
        $this->assertSame('13:00', $post->scheduled_at->format('H:i'));
    }

    #[Test]
    public function the_estimate_endpoint_reports_the_audience_size(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/composer/estimate', [
                'audience' => ['mode' => 'all'],
                'schedule_mode' => 'immediate',
            ])
            ->assertOk()
            ->assertJsonPath('accounts', 6);
    }

    #[Test]
    public function another_teams_posts_are_invisible(): void
    {
        $post = Post::factory()->create(['team_id' => $this->team->id]);

        [, $outsider] = $this->tenant();

        $this->actingAs($outsider)
            ->getJson("/api/v1/posts/{$post->id}")
            ->assertNotFound();
    }

    #[Test]
    public function guests_are_rejected(): void
    {
        $this->getJson('/api/v1/posts')->assertUnauthorized();
    }
}
