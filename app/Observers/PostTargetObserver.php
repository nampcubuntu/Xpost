<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\TargetStatus;
use App\Events\PostProgressChanged;
use App\Models\Post;
use App\Models\PostTarget;

/**
 * Keeps the denormalised counters on posts in step with their targets.
 *
 * Counters exist so the post list can render 10k-target campaigns without a
 * COUNT(*) per row. Updates are atomic increments rather than a recount, so two
 * workers finishing at the same moment cannot lose a tick.
 */
class PostTargetObserver
{
    public function updated(PostTarget $target): void
    {
        if (! $target->wasChanged('status')) {
            return;
        }

        // getOriginal() applies the cast, so this comes back as an enum rather
        // than the raw column value -- normalise before comparing.
        $original = $target->getOriginal('status');
        $from = $original instanceof TargetStatus ? $original->value : $original;
        $to = $target->status->value;

        $leaving = $this->column($from);
        $entering = $this->column($to);

        if ($leaving === $entering) {
            return;
        }

        // Two guarded statements rather than one GREATEST() expression: the
        // MySQL-only function would break on SQLite, which is what the test
        // suite and small dev installs run on.
        if ($entering !== null) {
            Post::withoutGlobalScopes()->whereKey($target->post_id)->increment($entering);
        }

        if ($leaving !== null) {
            Post::withoutGlobalScopes()
                ->whereKey($target->post_id)
                ->where($leaving, '>', 0)
                ->decrement($leaving);
        }

        $this->announce($target);
    }

    /**
     * Tell the page the counters moved.
     *
     * Read back rather than computed here: the increments above are atomic and
     * other workers are running the same statements, so the only numbers worth
     * sending are the ones the database now holds. This is the one read, and
     * the event uses it as given.
     *
     * Forced on the last target, because the throttle would otherwise swallow
     * exactly the message that matters -- a run whose final target lands inside
     * a throttled second would sit at 999/1000 until something else happened,
     * and nothing else is going to happen.
     */
    private function announce(PostTarget $target): void
    {
        $post = Post::withoutGlobalScopes()->find($target->post_id);

        if ($post === null) {
            return;
        }

        PostProgressChanged::announce($post, force: $post->targets_pending === 0);
    }

    /** Only the three states the UI counts have a column. */
    private function column(?string $status): ?string
    {
        return match ($status) {
            TargetStatus::Pending->value, TargetStatus::Queued->value => 'targets_pending',
            TargetStatus::Published->value => 'targets_published',
            TargetStatus::Failed->value => 'targets_failed',
            default => null,
        };
    }
}
