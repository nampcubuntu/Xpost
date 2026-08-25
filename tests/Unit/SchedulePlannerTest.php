<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ScheduleMode;
use App\Models\Post;
use App\Services\Publishing\SchedulePlanner;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SchedulePlannerTest extends TestCase
{
    private SchedulePlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->planner = new SchedulePlanner;
        Carbon::setTestNow('2026-08-25 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function draft(array $attributes): Post
    {
        return new Post(array_merge(['timezone' => 'Asia/Ho_Chi_Minh'], $attributes));
    }

    #[Test]
    public function fixed_time_gives_every_target_the_same_instant(): void
    {
        $post = $this->draft([
            'schedule_mode' => ScheduleMode::FixedTime,
            'scheduled_at' => '2026-08-25 18:00:00',
        ]);

        $first = $this->planner->slotFor($post, 0, 100);
        $last = $this->planner->slotFor($post, 99, 100);

        $this->assertTrue($first->equalTo($last));
    }

    #[Test]
    public function drip_spreads_evenly_across_the_window(): void
    {
        $post = $this->draft([
            'schedule_mode' => ScheduleMode::Drip,
            'scheduled_at' => '2026-08-25 10:00:00',
            'window_ends_at' => '2026-08-25 11:00:00',
        ]);

        $first = $this->planner->slotFor($post, 0, 5);
        $middle = $this->planner->slotFor($post, 2, 5);
        $last = $this->planner->slotFor($post, 4, 5);

        $this->assertSame('10:00', $first->format('H:i'));
        $this->assertSame('10:30', $middle->format('H:i'));
        $this->assertSame('11:00', $last->format('H:i'));
    }

    #[Test]
    public function jitter_stays_inside_the_window(): void
    {
        $post = $this->draft([
            'schedule_mode' => ScheduleMode::Jitter,
            'scheduled_at' => '2026-08-25 10:00:00',
            'jitter_seconds' => 600,
        ]);

        $start = Carbon::parse('2026-08-25 10:00:00');

        for ($i = 0; $i < 25; $i++) {
            $slot = $this->planner->slotFor($post, $i, 25);

            $this->assertGreaterThanOrEqual(0, $start->diffInSeconds($slot, false));
            $this->assertLessThanOrEqual(600, $start->diffInSeconds($slot, false));
        }
    }

    #[Test]
    public function immediate_mode_respects_the_per_minute_cap(): void
    {
        $post = $this->draft([
            'schedule_mode' => ScheduleMode::Immediate,
            'per_minute_cap' => 10,
        ]);

        // Targets 0-9 go now, 10-19 a minute later, and so on.
        $this->assertSame(0, (int) now()->diffInSeconds($this->planner->slotFor($post, 5, 100), false));
        $this->assertSame(60, (int) now()->diffInSeconds($this->planner->slotFor($post, 15, 100), false));
        $this->assertSame(120, (int) now()->diffInSeconds($this->planner->slotFor($post, 25, 100), false));
    }

    #[Test]
    public function immediate_without_a_cap_fires_everything_now(): void
    {
        $post = $this->draft(['schedule_mode' => ScheduleMode::Immediate]);

        $this->assertSame(0, (int) now()->diffInSeconds($this->planner->slotFor($post, 9999, 10000), false));
    }

    #[Test]
    public function per_account_timezone_shifts_by_the_accounts_offset(): void
    {
        // 20:00 Asia/Ho_Chi_Minh is 13:00 UTC, and UTC is what the column holds.
        // Building the post the other way round -- a UTC time labelled as if it
        // were local -- is what hid this for so long.
        $post = $this->draft([
            'schedule_mode' => ScheduleMode::PerAccountTimezone,
            'scheduled_at' => '2026-08-25 13:00:00',
        ]);

        $hanoi = $this->planner->slotFor($post, 0, 2, 'Asia/Ho_Chi_Minh');
        $london = $this->planner->slotFor($post, 1, 2, 'Europe/London');

        // Same local clock time, different absolute instants.
        $this->assertNotSame($hanoi->timestamp, $london->timestamp);
        $this->assertSame('20:00', $hanoi->setTimezone('Asia/Ho_Chi_Minh')->format('H:i'));
        $this->assertSame('20:00', $london->setTimezone('Europe/London')->format('H:i'));
    }

    #[Test]
    public function per_account_timezone_reads_the_hour_in_the_posts_timezone(): void
    {
        /*
         * The pool is global, so the stored instant is UTC while the hour the
         * user chose belongs to the post's own timezone. Reading the hour off
         * the UTC value instead sent every account in the world a post at 02:00
         * -- including the Vietnamese ones the schedule was written for.
         */
        $post = $this->draft([
            'schedule_mode' => ScheduleMode::PerAccountTimezone,
            'scheduled_at' => '2026-08-26 02:00:00',   // = 09:00 Asia/Ho_Chi_Minh
        ]);

        foreach (['Asia/Ho_Chi_Minh', 'America/New_York', 'Europe/London', 'Australia/Sydney'] as $zone) {
            $this->assertSame(
                '09:00',
                $this->planner->slotFor($post, 0, 4, $zone)->setTimezone($zone)->format('H:i'),
                "{$zone} should fire at its own 09:00",
            );
        }
    }

    #[Test]
    public function an_account_with_no_timezone_falls_back_to_the_posts(): void
    {
        $post = $this->draft([
            'schedule_mode' => ScheduleMode::PerAccountTimezone,
            'scheduled_at' => '2026-08-26 02:00:00',   // = 09:00 Asia/Ho_Chi_Minh
        ]);

        $slot = $this->planner->slotFor($post, 0, 1, null);

        $this->assertSame('09:00', $slot->setTimezone('Asia/Ho_Chi_Minh')->format('H:i'));
    }

    #[Test]
    public function it_summarises_the_delivery_window(): void
    {
        $post = $this->draft([
            'schedule_mode' => ScheduleMode::Drip,
            'scheduled_at' => '2026-08-25 10:00:00',
            'window_ends_at' => '2026-08-25 12:00:00',
        ]);

        $summary = $this->planner->summarise($post, 500);

        $this->assertSame(7200, $summary['duration_seconds']);
        $this->assertSame('10:00', $summary['first']->format('H:i'));
        $this->assertSame('12:00', $summary['last']->format('H:i'));
    }
}
