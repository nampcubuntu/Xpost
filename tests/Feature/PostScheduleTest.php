<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\PostSchedule;
use App\Models\XAccount;
use App\Models\XApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Recurring schedules: "each account posts 3-5 times a day at fixed slots".
 *
 * The generated post is an ordinary post, so the tests below care about the two
 * things that are genuinely new -- when an occurrence lands, and what happens to
 * one that was missed.
 */
class PostScheduleTest extends TestCase
{
    use RefreshDatabase;

    private $team;

    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team, $this->user] = $this->tenant();

        $app = XApp::factory()->create(['team_id' => $this->team->id]);
        XAccount::factory()->count(5)->create(['team_id' => $this->team->id, 'x_app_id' => $app->id]);

        Carbon::setTestNow('2026-08-25 08:00:00');   // Tuesday
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function schedule(array $attributes = []): PostSchedule
    {
        $schedule = PostSchedule::factory()->create(array_merge([
            'team_id' => $this->team->id,
            'created_by' => $this->user->id,
            'timezone' => 'UTC',
        ], $attributes));

        $schedule->refreshNextRun();

        return $schedule->fresh();
    }

    // ── Occurrence maths ────────────────────────────────────────────────────

    #[Test]
    public function it_finds_the_next_slot_later_today(): void
    {
        $schedule = $this->schedule(['times' => ['09:00', '13:00', '19:00']]);

        $this->assertSame('2026-08-25 09:00', $schedule->next_run_at->format('Y-m-d H:i'));
    }

    #[Test]
    public function it_rolls_to_tomorrow_once_the_days_slots_have_passed(): void
    {
        Carbon::setTestNow('2026-08-25 20:00:00');

        $schedule = $this->schedule(['times' => ['09:00', '13:00', '19:00']]);

        $this->assertSame('2026-08-26 09:00', $schedule->next_run_at->format('Y-m-d H:i'));
    }

    #[Test]
    public function it_honours_the_day_of_week_filter(): void
    {
        // Monday and Friday only; today is Tuesday.
        $schedule = $this->schedule(['times' => ['09:00'], 'days_of_week' => [1, 5]]);

        $this->assertSame('Friday', $schedule->next_run_at->format('l'));
    }

    #[Test]
    public function it_reads_times_in_the_schedules_own_timezone(): void
    {
        // 09:00 in Ho Chi Minh is 02:00 UTC, and 08:00 UTC is already past that.
        $schedule = $this->schedule(['times' => ['09:00'], 'timezone' => 'Asia/Ho_Chi_Minh']);

        $this->assertSame('2026-08-26 02:00', $schedule->next_run_at->setTimezone('UTC')->format('Y-m-d H:i'));
    }

    #[Test]
    public function a_paused_schedule_has_no_next_run(): void
    {
        $this->assertNull($this->schedule(['is_active' => false])->next_run_at);
    }

    #[Test]
    public function it_stops_at_the_end_date(): void
    {
        $schedule = $this->schedule([
            'times' => ['09:00'],
            'ends_on' => '2026-08-24',   // yesterday
        ]);

        $this->assertNull($schedule->next_run_at);
    }

    #[Test]
    public function duplicate_and_malformed_times_are_ignored(): void
    {
        $schedule = $this->schedule(['times' => ['09:00', '9:00', 'nonsense', '13:00']]);

        $this->assertSame(2, $schedule->postsPerDay());
    }

    // ── Generation ──────────────────────────────────────────────────────────

    #[Test]
    public function a_due_schedule_produces_a_post(): void
    {
        Queue::fake();

        $schedule = $this->schedule(['times' => ['09:00']]);
        Carbon::setTestNow('2026-08-25 09:00:30');

        $this->artisan('xpost:run-schedules')->assertSuccessful();

        $post = Post::withoutGlobalScopes()->where('post_schedule_id', $schedule->id)->first();

        $this->assertNotNull($post);
        $this->assertSame(PostStatus::Scheduled, $post->status);
        $this->assertSame('2026-08-25 09:00', $post->occurrence_at->format('Y-m-d H:i'));
    }

    #[Test]
    public function generating_advances_the_schedule_to_the_next_slot(): void
    {
        Queue::fake();

        $schedule = $this->schedule(['times' => ['09:00', '13:00']]);
        Carbon::setTestNow('2026-08-25 09:00:30');

        $this->artisan('xpost:run-schedules')->assertSuccessful();

        $schedule->refresh();

        $this->assertSame(1, $schedule->runs_count);
        $this->assertSame('2026-08-25 13:00', $schedule->next_run_at->format('Y-m-d H:i'));
    }

    #[Test]
    public function a_badly_missed_slot_is_dropped_not_fired_late(): void
    {
        Queue::fake();

        $schedule = $this->schedule(['times' => ['09:00'], 'catch_up_minutes' => 15]);

        // Two hours after the slot: an outage, not a slightly late cron.
        Carbon::setTestNow('2026-08-25 11:00:00');

        $this->artisan('xpost:run-schedules')->assertSuccessful();

        $this->assertSame(0, Post::withoutGlobalScopes()->where('post_schedule_id', $schedule->id)->count());
        $this->assertSame('2026-08-26 09:00', $schedule->fresh()->next_run_at->format('Y-m-d H:i'));
    }

    #[Test]
    public function a_slightly_late_slot_still_fires(): void
    {
        Queue::fake();

        $schedule = $this->schedule(['times' => ['09:00'], 'catch_up_minutes' => 15]);
        Carbon::setTestNow('2026-08-25 09:10:00');

        $this->artisan('xpost:run-schedules')->assertSuccessful();

        $this->assertSame(1, Post::withoutGlobalScopes()->where('post_schedule_id', $schedule->id)->count());
    }

    #[Test]
    public function running_twice_does_not_duplicate_an_occurrence(): void
    {
        Queue::fake();

        $schedule = $this->schedule(['times' => ['09:00']]);
        Carbon::setTestNow('2026-08-25 09:00:30');

        $this->artisan('xpost:run-schedules')->assertSuccessful();

        // Force the same slot to look due again, as a crash mid-run would.
        $schedule->forceFill(['next_run_at' => Carbon::parse('2026-08-25 09:00:00')])->save();
        $this->artisan('xpost:run-schedules')->assertSuccessful();

        $this->assertSame(1, Post::withoutGlobalScopes()->where('post_schedule_id', $schedule->id)->count());
    }

    #[Test]
    public function a_paused_schedule_generates_nothing(): void
    {
        Queue::fake();

        $schedule = $this->schedule(['times' => ['09:00'], 'is_active' => false]);
        Carbon::setTestNow('2026-08-25 09:00:30');

        $this->artisan('xpost:run-schedules')->assertSuccessful();

        $this->assertSame(0, Post::withoutGlobalScopes()->where('post_schedule_id', $schedule->id)->count());
    }

    #[Test]
    public function dry_run_reports_without_creating(): void
    {
        Queue::fake();

        $schedule = $this->schedule(['times' => ['09:00']]);
        Carbon::setTestNow('2026-08-25 09:00:30');

        $this->artisan('xpost:run-schedules', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Post::withoutGlobalScopes()->where('post_schedule_id', $schedule->id)->count());
        $this->assertSame(0, $schedule->fresh()->runs_count);
    }

    // ── API ─────────────────────────────────────────────────────────────────

    #[Test]
    public function it_creates_a_schedule_over_the_api(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/schedules', [
                'name' => 'Ba khung mỗi ngày',
                'times' => ['09:00', '13:00', '19:00'],
                'timezone' => 'UTC',
                'template' => [
                    'body' => '{Chào|Xin chào} [[handle]]',
                    'audience' => ['mode' => 'all'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('posts_per_day', 3)
            ->assertJsonPath('is_active', true);
    }

    #[Test]
    public function the_whole_template_survives_a_save(): void
    {
        /*
         * validated() drops anything without a rule of its own, so a template
         * declared only as `array` came back holding just the body -- the link
         * and the timing mode vanished silently on save.
         */
        $this->actingAs($this->user)
            ->postJson('/api/v1/schedules', [
                'name' => 'Có link',
                'times' => ['09:00'],
                'timezone' => 'UTC',
                'template' => [
                    'body' => 'xem thêm',
                    'destination_type' => 'website',
                    'website_url' => 'https://example.com',
                    'schedule_mode' => 'per_account_timezone',
                    'variant_strategy' => 'spintax',
                    'audience' => ['mode' => 'all', 'shuffle' => true],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('template.destination_type', 'website')
            ->assertJsonPath('template.website_url', 'https://example.com')
            ->assertJsonPath('template.schedule_mode', 'per_account_timezone')
            ->assertJsonPath('template.variant_strategy', 'spintax');
    }

    #[Test]
    public function the_preview_counts_accounts_missing_a_timezone(): void
    {
        // The pool is global, so "post at 09:00 local" is only as good as the
        // timezones on file; the ones without fall back to the schedule's.
        XAccount::query()->limit(2)->update(['timezone' => null]);

        $schedule = $this->schedule([
            'times' => ['09:00'],
            'template' => [
                'body' => 'chào',
                'schedule_mode' => 'per_account_timezone',
                'audience' => ['mode' => 'all'],
            ],
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/schedules/{$schedule->id}/preview")
            ->assertOk()
            ->assertJsonPath('per_account_local', true)
            ->assertJsonPath('accounts_without_timezone', 2);
    }

    #[Test]
    public function a_fixed_time_schedule_does_not_report_missing_timezones(): void
    {
        XAccount::query()->update(['timezone' => null]);

        $schedule = $this->schedule(['times' => ['09:00']]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/schedules/{$schedule->id}/preview")
            ->assertOk()
            ->assertJsonPath('per_account_local', false)
            ->assertJsonPath('accounts_without_timezone', 0);
    }

    #[Test]
    public function the_preview_prices_the_schedule(): void
    {
        $schedule = $this->schedule(['times' => ['09:00', '13:00']]);

        // 5 accounts x 2 slots = 10 posts a day, no link, so $0.015 each.
        $this->actingAs($this->user)
            ->getJson("/api/v1/schedules/{$schedule->id}/preview")
            ->assertOk()
            ->assertJsonPath('accounts', 5)
            ->assertJsonPath('posts_per_day', 10)
            ->assertJsonPath('cost_per_day_usd', 0.15)
            ->assertJsonPath('cost_per_month_usd', 4.5);
    }

    #[Test]
    public function the_preview_uses_the_link_price_when_there_is_a_destination(): void
    {
        $schedule = $this->schedule([
            'times' => ['09:00'],
            'template' => [
                'body' => 'xem thêm',
                'destination_type' => 'website',
                'website_url' => 'https://example.com',
                'audience' => ['mode' => 'all'],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/schedules/{$schedule->id}/preview")
            ->assertOk()
            ->assertJsonPath('has_link', true);

        // A link makes each post $0.20 instead of $0.015 -- 5 x 0.20 = $1.00.
        // Compared as a float: a whole-number price comes back JSON-decoded as int.
        $this->assertSame(1.0, (float) $response->json('cost_per_day_usd'));
    }

    #[Test]
    public function an_unsaved_schedule_can_be_priced(): void
    {
        // The editor quotes while the times are still being picked, so this must
        // work with no schedule row at all.
        $this->actingAs($this->user)
            ->postJson('/api/v1/schedules/preview', [
                'times' => ['09:00', '13:00', '19:00'],
                'template' => ['body' => 'chào', 'audience' => ['mode' => 'all']],
            ])
            ->assertOk()
            ->assertJsonPath('accounts', 5)
            ->assertJsonPath('posts_per_day', 15)
            ->assertJsonPath('unit_price_usd', 0.015)
            ->assertJsonPath('cost_per_month_usd', 6.75);
    }

    #[Test]
    public function toggling_pauses_and_clears_the_next_run(): void
    {
        $schedule = $this->schedule(['times' => ['09:00']]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/schedules/{$schedule->id}/toggle")
            ->assertOk()
            ->assertJsonPath('is_active', false)
            ->assertJsonPath('next_run_at', null);
    }

    #[Test]
    public function another_teams_schedule_is_invisible(): void
    {
        $schedule = $this->schedule();

        [, $outsider] = $this->tenant();

        $this->actingAs($outsider)
            ->getJson("/api/v1/schedules/{$schedule->id}")
            ->assertNotFound();
    }

    #[Test]
    public function a_schedule_starting_further_out_than_a_week_still_has_a_next_run(): void
    {
        /*
         * The occurrence search looks eight days ahead, which is all a weekly
         * pattern can need -- but a campaign told to begin in three weeks
         * matched no day at all and computed no next run. scopeDue reads a null
         * next_run_at as "never" and nothing recomputes it later, so the
         * schedule was saved, listed, shown as active, and silently dead.
         */
        $schedule = $this->schedule([
            'times' => ['09:00'],
            'days_of_week' => [],
            'starts_on' => now()->addDays(30)->toDateString(),
            'ends_on' => null,
        ]);

        $this->assertNotNull($schedule->next_run_at, 'a schedule starting in 30 days would never fire');
        $this->assertSame(
            now()->addDays(30)->toDateString(),
            $schedule->next_run_at->toDateString()
        );
    }

    #[Test]
    public function a_start_date_far_out_still_respects_the_chosen_weekday(): void
    {
        // Jumping to the start date must not jump past the day filter with it.
        $start = now()->addDays(30)->startOfDay();
        $monday = $start->copy()->next(Carbon::MONDAY);

        $schedule = $this->schedule([
            'times' => ['09:00'],
            'days_of_week' => [1],
            'starts_on' => $start->toDateString(),
            'ends_on' => null,
        ]);

        $this->assertSame(1, (int) $schedule->next_run_at->isoWeekday());
        $this->assertTrue($schedule->next_run_at->greaterThanOrEqualTo($start));
        $this->assertSame($monday->toDateString(), $schedule->next_run_at->toDateString());
    }

    #[Test]
    public function a_window_that_ends_before_it_starts_never_fires(): void
    {
        // The jump to the start date must not skip the end check.
        $schedule = $this->schedule([
            'times' => ['09:00'],
            'days_of_week' => [],
            'starts_on' => now()->addDays(30)->toDateString(),
            'ends_on' => now()->addDays(20)->toDateString(),
        ]);

        $this->assertNull($schedule->next_run_at);
    }

    #[Test]
    public function an_hour_that_does_not_exist_is_refused(): void
    {
        /*
         * The old rule was any two numbers separated by a colon, so "25:00"
         * saved cleanly, counted towards the cost estimate, and reached
         * setTime() -- which rolls it over into 01:00 the following day. A slot
         * nobody chose, at an hour nobody picked.
         */
        $this->actingAs($this->user->refresh());

        foreach (['25:00', '99:99', '12:60'] as $time) {
            $this->postJson('/api/v1/schedules', [
                'name' => 'Test',
                'times' => [$time],
                'template' => ['body' => 'xin chào', 'audience' => ['mode' => 'all']],
            ])->assertStatus(422, "{$time} was accepted as a time of day");
        }

        // The shape that must still be accepted, so the rule is not simply
        // refusing everything.
        $this->postJson('/api/v1/schedules', [
            'name' => 'Test',
            'times' => ['09:00', '23:59', '0:05'],
            'template' => ['body' => 'xin chào', 'audience' => ['mode' => 'all']],
        ])->assertCreated();
    }

    #[Test]
    public function a_stored_impossible_hour_is_not_counted_as_a_slot(): void
    {
        // Rows written before the rule tightened must not keep inflating the
        // estimate, which is accounts times slots per day.
        $schedule = $this->schedule(['times' => ['09:00', '25:00', '99:99']]);

        $this->assertSame(1, $schedule->postsPerDay());
    }
}
