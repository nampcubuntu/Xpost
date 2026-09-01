<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Something in the account pool moved.
 *
 * Deliberately carries no account.
 *
 * PostProgressChanged can name its post because a fan-out to ten thousand
 * accounts is one post whose counters move -- one row, one message a second.
 * Health is the other way round: the same fan-out moves ten thousand account
 * rows, one per target. Throttling per account, the way that event throttles
 * per post, would still allow ten thousand messages a second, and the page
 * showing fifty of them would discard almost all of it.
 *
 * So this is a nudge, throttled per team, and the page re-reads the fifty rows
 * it is actually displaying. One message and one request per window, whether
 * the run is ten accounts or ten thousand.
 */
class AccountHealthChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * Longer than the post throttle on purpose.
     *
     * A progress bar is watched; a health column is glanced at. One request
     * every few seconds is the cost of this per open tab, and the numbers it
     * carries move by one point at a time.
     */
    private const THROTTLE_SECONDS = 5;

    public function __construct(public readonly int $teamId) {}

    /**
     * Nudge the team's pages, unless they were nudged a moment ago.
     *
     * An atomic add rather than a read-then-write: twenty workers finish inside
     * the same second by design, which is exactly when a check-then-set lets
     * all twenty through.
     *
     * Swallowed for the same reason PostProgressChanged swallows: the caller is
     * a publish worker mid-fan-out, and Reverb being stopped is not a reason to
     * fail a post X has already charged for.
     */
    public static function announce(int $teamId): void
    {
        if (! Cache::add("account-health:{$teamId}", 1, self::THROTTLE_SECONDS)) {
            return;
        }

        try {
            broadcast(new self($teamId));
        } catch (Throwable $e) {
            Log::channel('xpost')->debug('Account health broadcast dropped', [
                'team_id' => $teamId,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("team.{$this->teamId}")];
    }

    public function broadcastAs(): string
    {
        return 'account.health';
    }
}
