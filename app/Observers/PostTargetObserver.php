<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\TargetStatus;
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
