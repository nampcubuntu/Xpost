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
 * New engagement numbers came back from X.
 *
 * The analytics screen has two halves that move at completely different speeds.
 * Publishing changes the moment a post goes out, and PostProgressChanged
 * already carries that. Engagement changes only when SyncTweetMetrics runs,
 * which is hourly and only for posts from the last 72 hours -- so pushing the
 * publishing half live and leaving the other to a page reload would leave half
 * the screen quietly hours old with nothing saying so.
 *
 * Deliberately carries no numbers.
 *
 * The sync is one job per account, so a run across the pool is a thousand jobs
 * finishing over a few minutes. A message per account would be a thousand
 * messages describing one refresh, and the page would discard almost all of
 * them. This is a nudge, throttled per team, and the page re-reads the figures
 * it is actually showing.
 */
class MetricsSynced implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * Much longer than the publishing throttles, because the data is.
     *
     * A progress bar is watched second by second; these counters move once an
     * hour. Half a minute between nudges still lands a refresh well inside the
     * window where anything changed, and keeps a sweep over ten thousand
     * accounts to a handful of requests per open tab rather than one per job.
     */
    private const THROTTLE_SECONDS = 30;

    public function __construct(public readonly int $teamId) {}

    /**
     * Nudge the team's pages, unless they were nudged a moment ago.
     *
     * An atomic add rather than a read-then-write: the workers finish inside
     * the same second by design, which is exactly when a check-then-set lets
     * all of them through.
     *
     * Swallowed for the same reason the other broadcasts swallow: the caller is
     * a metrics worker, and Reverb being stopped is not a reason to lose a
     * snapshot that was already fetched and stored.
     */
    public static function announce(int $teamId): void
    {
        if (! Cache::add("metrics-synced:{$teamId}", 1, self::THROTTLE_SECONDS)) {
            return;
        }

        try {
            broadcast(new self($teamId));
        } catch (Throwable $e) {
            Log::channel('xpost')->debug('Metrics broadcast dropped', [
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
        return 'metrics.synced';
    }
}
