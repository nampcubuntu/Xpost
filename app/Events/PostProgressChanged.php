<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Post;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\InteractsWithTime;
use Throwable;

/**
 * A post's fan-out moved.
 *
 * Sent as the aggregate rather than per target. A post going out to ten
 * thousand accounts finishes ten thousand targets, and a message each is ten
 * thousand messages to render one counter that only has to look like it is
 * moving. What the page needs is the current numbers, which is one message
 * whatever caused it.
 *
 * Broadcast to the team, not to the post: an operator watching the list is
 * watching every post on it, and a channel per row would mean a hundred
 * subscriptions on one page and a resubscribe on every filter change.
 */
class PostProgressChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, InteractsWithTime;

    /** At most one message per post per second, however fast the workers go. */
    private const THROTTLE_SECONDS = 1;

    public function __construct(
        public readonly int $postId,
        public readonly int $teamId,
        public readonly string $status,
        public readonly int $total,
        public readonly int $pending,
        public readonly int $published,
        public readonly int $failed,
    ) {}

    /**
     * Announce a post, unless it was announced a moment ago.
     *
     * The gate is here rather than in the observer so every caller gets it, and
     * it is an atomic add rather than a read-then-write: twenty workers finish
     * inside the same second on purpose, which is exactly when a check-then-set
     * lets all twenty through.
     *
     * The last change is never dropped: a post whose numbers settle inside a
     * throttled second would otherwise sit at 998/1000 until something else
     * happened. `$force` is passed when the fan-out reaches a terminal state.
     *
     * The post must arrive with its counter columns loaded and current. That is
     * the caller's job because the caller has just read it -- and a model that
     * is missing them throws under preventAccessingMissingAttributes, into the
     * catch below, where it becomes a message that silently never arrives.
     */
    public static function announce(Post $post, bool $force = false): void
    {
        /*
         * The caller's model, not a re-read of it.
         *
         * This used to fetch the row again to be sure the counters were the
         * ones the database now holds -- but the only caller that matters is
         * the observer, which has already read it after its own increments.
         * Two selects per target is ten thousand selects on a ten thousand
         * account fan-out, to send at most one message a second.
         */
        $fresh = $post;

        if (! $force && ! Cache::add("post-progress:{$fresh->id}", 1, self::THROTTLE_SECONDS)) {
            return;
        }

        /*
         * Sent inline, and never allowed to matter.
         *
         * Inline because a progress ping routed through the queue arrives
         * behind the publishing jobs it is reporting on -- and it would make a
         * status indicator depend on Horizon being up, which is a strange thing
         * for a status indicator to depend on.
         *
         * Swallowed because the caller is a publish worker mid-fan-out. Reverb
         * is a separate process that can be stopped, restarted or not installed
         * at all, and none of that is a reason to fail a post that X has
         * already accepted. The page falls back to polling; the campaign does
         * not fall back to anything.
         */
        try {
            broadcast(new self(
                postId: $fresh->id,
                teamId: $fresh->team_id,
                status: $fresh->status->value,
                total: $fresh->targets_total,
                pending: $fresh->targets_pending,
                published: $fresh->targets_published,
                failed: $fresh->targets_failed,
            ));
        } catch (Throwable $e) {
            Log::channel('xpost')->debug('Progress broadcast dropped', [
                'post_id' => $fresh->id,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("team.{$this->teamId}")];
    }

    /** A name the client can listen for without knowing the PHP namespace. */
    public function broadcastAs(): string
    {
        return 'post.progress';
    }

    /**
     * The same shape the list already renders, so a row is patched rather than
     * re-derived. team_id is deliberately absent: it is in the channel name,
     * and a payload does not need to repeat what the subscription proved.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->postId,
            'status' => $this->status,
            'progress' => [
                'total' => $this->total,
                'pending' => $this->pending,
                'published' => $this->published,
                'failed' => $this->failed,
                'percent' => $this->total > 0
                    ? (int) round(($this->published + $this->failed) / $this->total * 100)
                    : 0,
            ],
        ];
    }
}
