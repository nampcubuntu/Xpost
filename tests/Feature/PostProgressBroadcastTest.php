<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TargetStatus;
use App\Events\PostProgressChanged;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\Team;
use App\Models\User;
use App\Models\XAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The post list watching its own fan-out.
 *
 * Four things have to hold, and only the first is about the feature working at
 * all: the counters have to reach the browser, they must not reach the wrong
 * browser, ten thousand targets must not become ten thousand messages, and none
 * of it may be able to fail a publish.
 */
class PostProgressBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team] = $this->tenant();
        Cache::flush();
    }

    /**
     * A broadcaster that actually authorises.
     *
     * phpunit.xml sets BROADCAST_CONNECTION=null, and NullBroadcaster answers
     * every /broadcasting/auth with 200 and an empty body without consulting a
     * single channel callback -- so a channel test against the default config
     * passes whatever the callback says, including nothing at all.
     *
     * The channels are registered again afterwards, from the real routes file.
     * Registration lands on whichever broadcaster is current, and at boot that
     * was the null one -- so switching drivers hands you a broadcaster that has
     * never heard of any channel, and every request is refused. That looks
     * exactly like the authorisation working, which is the worst way for a
     * security test to fail.
     */
    private function useRealBroadcaster(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);

        // The real file, so the callback under test is the one that ships.
        require base_path('routes/channels.php');
    }

    /**
     * Refreshed, because announce() reads the counter columns off the model it
     * is given. A factory model carries only what the factory set, and
     * preventAccessingMissingAttributes turns the rest into a throw -- which
     * the event catches, so the symptom is a message that never arrives.
     */
    private function draft(array $overrides = []): Post
    {
        return Post::factory()->create($overrides + [
            'team_id' => $this->team->id,
            'targets_total' => 4,
            'targets_pending' => 4,
        ])->fresh();
    }

    #[Test]
    public function finishing_a_target_announces_the_post(): void
    {
        Event::fake([PostProgressChanged::class]);

        $post = $this->draft();
        $account = XAccount::factory()->create(['team_id' => $this->team->id]);

        $target = PostTarget::factory()->create([
            'post_id' => $post->id,
            'team_id' => $this->team->id,
            'x_account_id' => $account->id,
            'status' => TargetStatus::Pending,
        ]);

        $target->forceFill(['status' => TargetStatus::Published])->save();

        Event::assertDispatched(PostProgressChanged::class, fn (PostProgressChanged $e) => $e->postId === $post->id);
    }

    #[Test]
    public function the_message_carries_the_numbers_the_row_renders(): void
    {
        /*
         * Patched rather than re-fetched on the client, so the payload has to
         * be the same shape the list already draws -- a message that made the
         * page reload would undo the point of not polling.
         */
        $post = $this->draft(['targets_total' => 10, 'targets_pending' => 3, 'targets_published' => 6, 'targets_failed' => 1]);

        $event = new PostProgressChanged(
            postId: $post->id,
            teamId: $post->team_id,
            status: $post->status->value,
            total: 10,
            pending: 3,
            published: 6,
            failed: 1,
        );

        $payload = $event->broadcastWith();

        $this->assertSame($post->id, $payload['id']);
        $this->assertSame(3, $payload['progress']['pending']);
        // 6 published + 1 failed of 10 is 70% done, not 60% -- a failed target
        // is finished, and a bar that never reaches the end is a bar that lies.
        $this->assertSame(70, $payload['progress']['percent']);
    }

    #[Test]
    public function it_goes_to_the_teams_channel_and_no_other(): void
    {
        // The channel name is the whole of the tenancy here: anything on it is
        // readable by anyone the channel lets in.
        $event = new PostProgressChanged(1, $this->team->id, 'publishing', 1, 1, 0, 0);

        $this->assertSame(
            ["private-team.{$this->team->id}"],
            array_map(fn ($channel) => (string) $channel, $event->broadcastOn())
        );
    }

    #[Test]
    public function another_teams_member_cannot_join_the_channel(): void
    {
        /*
         * A channel name is a string the browser chooses, so without the
         * membership check any signed-in user could name any team and watch
         * its campaigns go out.
         */
        $this->useRealBroadcaster();

        [$other, $stranger] = $this->tenant();

        $this->actingAs($stranger->refresh())
            ->postJson('/broadcasting/auth', ['channel_name' => "private-team.{$this->team->id}", 'socket_id' => '1.1'])
            ->assertForbidden();
    }

    #[Test]
    public function a_member_can_join_their_own_teams_channel(): void
    {
        // The other half: the check must not refuse what it should allow.
        $this->useRealBroadcaster();

        $member = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->team->members()->attach($member->id, ['role' => 'editor']);

        $this->actingAs($member->refresh())
            ->postJson('/broadcasting/auth', ['channel_name' => "private-team.{$this->team->id}", 'socket_id' => '1.1'])
            ->assertOk();
    }

    #[Test]
    public function a_burst_of_targets_is_one_message_not_a_thousand(): void
    {
        /*
         * The reason the throttle exists. Ten thousand targets finishing is
         * ten thousand ticks of a counter that only has to look like it is
         * moving, and a message each would flood the socket to render the same
         * number repeatedly.
         */
        Event::fake([PostProgressChanged::class]);

        $post = $this->draft();

        foreach (range(1, 20) as $ignored) {
            PostProgressChanged::announce($post);
        }

        Event::assertDispatchedTimes(PostProgressChanged::class, 1);
    }

    #[Test]
    public function the_last_change_is_never_the_one_that_gets_dropped(): void
    {
        /*
         * A run whose final target lands inside a throttled second would sit at
         * 999/1000 until something else happened -- and nothing else is going
         * to happen. So the terminal update is forced past the gate.
         */
        Event::fake([PostProgressChanged::class]);

        $post = $this->draft();

        PostProgressChanged::announce($post);
        PostProgressChanged::announce($post);
        PostProgressChanged::announce($post, force: true);

        Event::assertDispatchedTimes(PostProgressChanged::class, 2);
    }

    #[Test]
    public function a_broadcaster_that_is_down_cannot_fail_a_publish(): void
    {
        /*
         * The caller is a publish worker mid-fan-out. Reverb is a separate
         * process that can be stopped, restarted or never installed, and none
         * of that is a reason to fail a post X has already accepted.
         */
        config(['broadcasting.default' => 'reverb']);
        config(['broadcasting.connections.reverb.options' => [
            'host' => '127.0.0.1',
            // Nothing is listening here, which is the point.
            'port' => 1,
            'scheme' => 'http',
            'useTLS' => false,
        ]]);

        $post = $this->draft();

        PostProgressChanged::announce($post, force: true);

        // Reaching this line without an exception is the assertion.
        $this->assertTrue(true);
    }
}
