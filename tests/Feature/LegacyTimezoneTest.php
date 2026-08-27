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
