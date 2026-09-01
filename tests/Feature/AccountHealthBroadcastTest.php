<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\AccountHealthChanged;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The account pool, watched without reloading.
 *
 * The shape is the opposite of the posts list's, and that is the whole point.
 * A fan-out to ten thousand accounts moves one post's counters -- one row, one
 * message a second -- and ten thousand account rows, one per target. So this
 * event names no account and is throttled per team, and the page re-reads the
 * fifty rows it is showing.
 */
class AccountHealthBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team] = $this->tenant();
        Cache::flush();
    }

    #[Test]
    public function it_goes_to_the_teams_channel_and_no_other(): void
    {
        $event = new AccountHealthChanged($this->team->id);

        // "private-" is the prefix a PrivateChannel stringifies with, and it is
        // what proves this is not readable by anyone who guesses a team id.
        $this->assertSame(
            ["private-team.{$this->team->id}"],
            array_map(fn ($channel) => (string) $channel, $event->broadcastOn())
        );
        $this->assertSame('account.health', $event->broadcastAs());
    }

    #[Test]
    public function a_fan_out_is_one_message_not_ten_thousand(): void
    {
        /*
         * The reason this event carries no account. Throttling per account, the
         * way PostProgressChanged throttles per post, would still allow one
         * message per account per second -- and a run touching the whole pool
         * would put ten thousand of those on the wire for a page rendering
         * fifty rows.
         */
        Event::fake([AccountHealthChanged::class]);

        for ($i = 0; $i < 500; $i++) {
            AccountHealthChanged::announce($this->team->id);
        }

        Event::assertDispatchedTimes(AccountHealthChanged::class, 1);
    }

    #[Test]
    public function another_team_publishing_does_not_nudge_this_one(): void
    {
        // The throttle is a shared cache key, so it has to be keyed by team or
        // one busy tenant would silence every other tenant's page.
        Event::fake([AccountHealthChanged::class]);

        [$other] = $this->tenant();

        AccountHealthChanged::announce($this->team->id);
        AccountHealthChanged::announce($other->id);

        Event::assertDispatchedTimes(AccountHealthChanged::class, 2);
    }

    #[Test]
    public function the_window_reopens(): void
    {
        // Otherwise the first message of a campaign would be the only one, and
        // the column would freeze at whatever it read a second in.
        Event::fake([AccountHealthChanged::class]);

        AccountHealthChanged::announce($this->team->id);
        Cache::forget("account-health:{$this->team->id}");
        AccountHealthChanged::announce($this->team->id);

        Event::assertDispatchedTimes(AccountHealthChanged::class, 2);
    }

    #[Test]
    public function a_broadcaster_that_is_down_cannot_fail_a_publish(): void
    {
        /*
         * The caller is a publish worker mid-fan-out. Reverb is a separate
         * process that can be stopped, restarted, or never installed, and none
         * of that is a reason to fail a post X has already charged for.
         */
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => null]);

        AccountHealthChanged::announce($this->team->id);

        $this->assertTrue(true, 'announce() must not throw when the broadcaster cannot send');
    }
}
