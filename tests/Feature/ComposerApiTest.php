<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Enums\ReplySettings;
use App\Jobs\FanOutPost;
use App\Models\Post;
use App\Models\XAccount;
use App\Models\XApp;
use App\Support\TeamContext;
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
    public function a_website_hostname_is_saved_as_an_https_url(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/posts', $this->payload([
                'website_url' => 'video5.twimg.com.es/watchfull',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.website_url', 'https://video5.twimg.com.es/watchfull');
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

    #[Test]
    public function the_composer_offers_every_reply_setting_and_flags_the_premium_ones(): void
    {
        /*
         * All five, because all five are real API values: the OpenAPI schema
         * for POST /2/tweets lists following, mentionedUsers, subscribers and
         * verified, with everyone sent by omitting the field.
         *
         * Two of them need X Premium on the posting account. That is said on
         * the row rather than settled by withholding the option -- a post fans
         * out to a thousand accounts, so it is a decision worth making with the
         * fact in hand.
         */
        $offered = collect($this->actingAs($this->user->refresh())
            ->getJson('/api/v1/composer/options')->assertOk()->json('reply_settings'));

        $this->assertSame(
            ['everyone', 'following', 'mentionedUsers', 'subscribers', 'verified'],
            $offered->pluck('value')->all()
        );

        // Each carries the icon the menu draws, so the list reads at a glance.
        $this->assertSame(['globe', 'members', 'at', 'members', 'badge'], $offered->pluck('icon')->all());

        // And the two that need Premium are flagged, which is what lets the
        // menu offer them without the constraint being a surprise.
        $this->assertSame(['subscribers', 'verified'], $offered->where('premium', true)->pluck('value')->values()->all());
    }

    #[Test]
    public function everyone_is_sent_by_leaving_the_field_out(): void
    {
        // Which is what the API asks for: "everyone" is not in its enum, it is
        // the default you get by saying nothing.
        $this->assertNull(ReplySettings::Everyone->toApiValue());
        $this->assertSame('mentionedUsers', ReplySettings::MentionedUsers->toApiValue());
    }

    #[Test]
    public function a_premium_only_setting_is_still_a_valid_stored_value(): void
    {
        // Dropped from the menu, not from the enum: a post saved before the
        // menu changed must still load and still publish.
        $this->assertNotNull(ReplySettings::tryFrom('subscribers'));
        $this->assertSame('verified', ReplySettings::Verified->toApiValue());
    }

    #[Test]
    public function another_teams_accounts_cannot_be_named_as_the_audience(): void
    {
        /*
         * AudienceResolver filters on team_id, so this was never a leak -- the
         * id resolved to nothing. But it passed validation, so the post was
         * accepted and then published to nobody, with the zero visible only in
         * an estimate nobody has to read.
         */
        [$other] = $this->tenant();
        $stranger = XAccount::factory()->create(['team_id' => $other->id]);

        TeamContext::set($this->team->id);

        $this->actingAs($this->user->refresh())
            ->postJson('/api/v1/posts', $this->payload([
                'audience' => ['mode' => 'accounts', 'accounts' => [$stranger->id]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('audience.accounts.0');
    }

    #[Test]
    public function this_teams_accounts_are_still_accepted(): void
    {
        // The other half: tightening the rule must not refuse what it should.
        $mine = XAccount::query()->where('team_id', $this->team->id)->firstOrFail();

        $this->actingAs($this->user->refresh())
            ->postJson('/api/v1/posts', $this->payload([
                'audience' => ['mode' => 'accounts', 'accounts' => [$mine->id]],
            ]))
            ->assertCreated();
    }
}
