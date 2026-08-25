<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\WallClock;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WallClockTest extends TestCase
{
    #[Test]
    public function a_naive_wall_clock_is_read_in_the_given_timezone(): void
    {
        // What <input type="datetime-local"> sends. 20:00 in Hanoi is 13:00 UTC.
        $this->assertSame(
            '2026-08-25 13:00:00',
            WallClock::toAppTimezone('2026-08-25T20:00', 'Asia/Ho_Chi_Minh'),
        );
    }

    #[Test]
    public function the_same_wall_clock_means_a_different_instant_elsewhere(): void
    {
        $hanoi = WallClock::toAppTimezone('2026-08-25T20:00', 'Asia/Ho_Chi_Minh');
        $newYork = WallClock::toAppTimezone('2026-08-25T20:00', 'America/New_York');

        $this->assertNotSame($hanoi, $newYork);
        $this->assertSame('2026-08-26 00:00:00', $newYork);
    }

    #[Test]
    public function a_value_that_already_carries_an_offset_is_not_reinterpreted(): void
    {
        // Unambiguous input must be converted, never re-read against $timezone.
        $this->assertSame(
            '2026-08-25 13:00:00',
            WallClock::toAppTimezone('2026-08-25T20:00:00+07:00', 'America/New_York'),
        );

        $this->assertSame(
            '2026-08-25 13:00:00',
            WallClock::toAppTimezone('2026-08-25T13:00:00Z', 'America/New_York'),
        );
    }

    #[Test]
    public function it_falls_back_to_the_app_timezone_when_none_is_given(): void
    {
        $this->assertSame('2026-08-25 20:00:00', WallClock::toAppTimezone('2026-08-25T20:00', null));
    }

    #[Test]
    public function empty_and_malformed_values_pass_through_untouched(): void
    {
        // The `date` validation rule is what should report these, not a silent
        // rewrite into some arbitrary instant.
        $this->assertNull(WallClock::toAppTimezone(null, 'UTC'));
        $this->assertSame('', WallClock::toAppTimezone('', 'UTC'));
        $this->assertSame('không phải ngày', WallClock::toAppTimezone('không phải ngày', 'UTC'));
    }
}
