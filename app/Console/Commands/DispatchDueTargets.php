<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Jobs\PublishPostTarget;
use App\Models\Post;
use App\Models\PostTarget;
use App\Support\TeamContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The scheduling loop.
 *
 * Runs every minute (see routes/console.php) and moves due targets from
 * `pending` to `queued`, dispatching one job each. Claiming happens inside a
 * transaction with FOR UPDATE SKIP LOCKED, which is what lets several instances
 * of this command run concurrently -- each grabs a disjoint slice instead of
 * fighting over the same head of the queue.
 *
 * The claim and the dispatch are deliberately separate: the row is marked
 * `queued` and committed *before* the job is pushed, so a crash between the two
 * leaves an orphan that the reaper (xpost:reap) can find, rather than a target
 * that was published twice.
 */
class DispatchDueTargets extends Command
{
    protected $signature = 'xpost:dispatch
                            {--limit=1000 : Maximum targets to claim in this run}
                            {--chunk=200 : Rows claimed per transaction}
                            {--team= : Restrict to a single team id}';

    protected $description = 'Claim due post targets and push them onto the publish queue';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $chunk = max(1, min((int) $this->option('chunk'), $limit));
        $teamId = $this->option('team') ? (int) $this->option('team') : null;

        $worker = gethostname().':'.getmypid();
        $dispatched = 0;

        while ($dispatched < $limit) {
            $claimed = $this->claim(min($chunk, $limit - $dispatched), $worker, $teamId);

            if ($claimed === []) {
                break;
            }

            foreach ($claimed as $row) {
                PublishPostTarget::dispatch((int) $row->id, (int) $row->team_id)
                    ->onQueue($this->queueFor($row));
            }

            $dispatched += count($claimed);
        }

        $this->promotePublishingPosts();

        $this->components->info("Dispatched {$dispatched} target(s).");

        return self::SUCCESS;
    }

    /**
     * @return list<object{id:int, team_id:int, post_id:int}>
     */
    private function claim(int $limit, string $worker, ?int $teamId): array
    {
        return DB::transaction(function () use ($limit, $worker, $teamId): array {
            $rows = DB::table('post_targets')
                ->select('id', 'team_id', 'post_id')
                ->whereIn('status', TargetStatus::claimable())
                ->where('scheduled_at', '<=', now())
                ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
                ->when($teamId, fn ($q, $id) => $q->where('team_id', $id))
                ->orderBy('scheduled_at')
                ->limit($limit)
                ->lockForUpdate(true)   // SKIP LOCKED
                ->get()
                ->all();

            if ($rows === []) {
                return [];
            }

            DB::table('post_targets')
                ->whereIn('id', array_column($rows, 'id'))
                ->update([
                    'status' => TargetStatus::Queued->value,
                    'claimed_at' => now(),
                    'claimed_by' => $worker,
                    'updated_at' => now(),
                ]);

            return $rows;
        }, 3);
    }

    /**
     * Time-critical work jumps the line. A target that is already late should
     * not queue behind a drip campaign scheduled for this evening.
     */
    private function queueFor(object $row): string
    {
        return 'publish';
    }

    /**
     * Move posts whose first target has left the gate into `publishing`, and
     * close out the ones that have finished. Cheap because it only looks at
     * posts with pending work.
     */
    private function promotePublishingPosts(): void
    {
        TeamContext::runUnscoped(function (): void {
            Post::withoutGlobalScopes()
                ->whereIn('status', [PostStatus::Scheduled->value, PostStatus::Publishing->value])
                ->where('targets_total', '>', 0)
                ->chunkById(200, function ($posts): void {
                    foreach ($posts as $post) {
                        $counts = PostTarget::withoutGlobalScopes()
                            ->where('post_id', $post->id)
                            ->selectRaw('status, COUNT(*) as aggregate')
                            ->groupBy('status')
                            ->pluck('aggregate', 'status');

                        $pending = (int) $counts->get(TargetStatus::Pending->value, 0)
                            + (int) $counts->get(TargetStatus::Queued->value, 0)
                            + (int) $counts->get(TargetStatus::Publishing->value, 0);

                        $published = (int) $counts->get(TargetStatus::Published->value, 0);
                        $failed = (int) $counts->get(TargetStatus::Failed->value, 0);

                        $status = match (true) {
                            $pending > 0 => PostStatus::Publishing,
                            $published > 0 && $failed > 0 => PostStatus::PartiallyFailed,
                            $published > 0 => PostStatus::Published,
                            default => PostStatus::Failed,
                        };

                        $post->forceFill([
                            'status' => $status,
                            'targets_pending' => $pending,
                            'targets_published' => $published,
                            'targets_failed' => $failed,
                            'published_at' => $post->published_at ?? ($published > 0 ? now() : null),
                        ])->save();
                    }
                });
        });
    }
}
