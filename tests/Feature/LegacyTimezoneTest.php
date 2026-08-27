<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Team;
use App\Models\XAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A timezone name the browser hands out has to be one the app accepts.
 *
 * Laravel's bare `timezone` rule lists DateTimeZone::ALL, which leaves out the
 * 179 backward-compatibility aliases. Browsers still answer with them --
 * Intl.DateTimeFormat().resolvedOptions().timeZone on a machine in Vietnam can
 * say Asia/Saigon rather than Asia/Ho_Chi_Minh -- so the composer refused to
 * save with "must be a valid timezone" about a timezone PHP itself accepts,
 * and it refused on the one machine that had no way to answer differently.
 */
class LegacyTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team, $user] = $this->tenant();
        $this->actingAs($user->refresh());
    }

    /** @return array<string, array{0: string}> */
    public static function legacyNames(): array
    {
        return [
            // The one that broke it, and its neighbours in the same list.
            'Asia/Saigon' => ['Asia/Saigon'],
            'Asia/Calcutta' => ['Asia/Calcutta'],
            'Europe/Kiev' => ['Europe/Kiev'],
            'Asia/Rangoon' => ['Asia/Rangoon'],
            'US/Pacific' => ['US/Pacific'],
        ];
    }

    #[Test]
    #[DataProvider('legacyNames')]
    public function a_post_saves_with_a_legacy_timezone_name(string $timezone): void
    {
        $this->postJson('/api/v1/posts', [
            'body' => 'xin chào',
            'destination_type' => 'none',
            'schedule_mode' => 'immediate',
            'timezone' => $timezone,
            'audience' => ['mode' => 'all'],
        ])->assertCreated();
    }

    #[Test]
    public function a_legacy_name_keeps_the_offset_its_modern_name_has(): void
    {
        /*
         * Accepting it is only right because PHP treats the two as the same
         * zone. If they ever diverged, accepting the alias would schedule posts
         * an hour out and nothing would say so.
         */
        $legacy = new \DateTimeZone('Asia/Saigon');
        $modern = new \DateTimeZone('Asia/Ho_Chi_Minh');
        $at = new \DateTime('2026-08-28 09:00');

        $this->assertSame($modern->getOffset($at), $legacy->getOffset($at));
    }

    #[Test]
    public function an_account_takes_a_legacy_timezone_too(): void
    {
        // The bulk toolbar and the row picker feed the same column.
        $account = XAccount::factory()->create(['team_id' => $this->team->id]);

        $this->putJson("/api/v1/accounts/{$account->id}", ['timezone' => 'Asia/Saigon'])
            ->assertOk();

        $this->assertSame('Asia/Saigon', $account->fresh()->timezone);
    }

    #[Test]
    public function the_picker_lists_every_zone_ordered_by_offset(): void
    {
        /*
         * By offset, the way X Ads Manager orders its own, because that is how
         * the question is asked: nobody wants "the zones beginning with P",
         * they want UTC+7 -- and alphabetically the zones an hour apart are
         * four hundred rows apart.
         */
        $zones = $this->getJson('/api/v1/timezones')->assertOk()->json('data');

        $this->assertGreaterThan(400, count($zones));

        $offsets = array_column($zones, 'offset_minutes');
        $sorted = $offsets;
        sort($sorted);

        $this->assertSame($sorted, $offsets);
        $this->assertSame('Pacific/Midway', $zones[0]['value']);
    }

    #[Test]
    public function the_offset_reads_the_way_a_person_says_it(): void
    {
        // "UTC+07:00" in a column reads as a timestamp; the minutes only carry
        // information for the half-hour zones.
        $zones = collect($this->getJson('/api/v1/timezones')->json('data'))->keyBy('value');

        $this->assertSame('UTC+7', $zones['Asia/Ho_Chi_Minh']['offset']);
        $this->assertSame('UTC+5:30', $zones['Asia/Kolkata']['offset']);
        $this->assertSame('UTC', $zones['UTC']['offset']);
    }

    #[Test]
    public function nonsense_is_still_refused(): void
    {
        // Widened, not opened: the rule still has to catch a typo.
        $this->postJson('/api/v1/posts', [
            'body' => 'xin chào',
            'destination_type' => 'none',
            'schedule_mode' => 'immediate',
            'timezone' => 'Asia/Sai_Gon',
            'audience' => ['mode' => 'all'],
        ])->assertStatus(422)->assertJsonValidationErrors('timezone');
    }
}
