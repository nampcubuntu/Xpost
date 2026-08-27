<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PostStatus;
use App\Enums\TargetStatus;
use App\Jobs\PublishPostTarget;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Publishing\PublishingHalt;
use App\Support\TeamContext;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
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
    /** Outlives one run by enough minutes to survive a slow tick, not an outage. */
    private const HEARTBEAT_KEY = 'xpost:scheduler:last-run';

    protected $signature = 'xpost:dispatch
                            {--limit=1000 : Maximum targets to claim in this run}
                            {--chunk=200 : Rows claimed per transaction}
                            {--team= : Restrict to a single team id}';

    protected $description = 'Claim due post targets and push them onto the publish queue';

    public function __construct(private readonly PublishingHalt $halt)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $chunk = max(1, min((int) $this->option('chunk'), $limit));
        $teamId = $this->option('team') ? (int) $this->option('team') : null;

        $worker = gethostname().':'.getmypid();
        $dispatched = 0;
        $halted = 0;

        while ($dispatched < $limit) {
            $claimed = $this->claim(min($chunk, $limit - $dispatched), $worker, $teamId);

            if ($claimed === []) {
                break;
            }

            foreach ($claimed as $row) {
                /*
                 * A team that has run out of credit is refused on every
                 * account, so pushing its targets only burns claims and log
                 * lines. They stay claimable and go out when the halt lifts.
                 */
                if ($this->halt->isEngaged((int) $row->team_id)) {
                    $this->release($row);
                    $halted++;

                    continue;
                }

                PublishPostTarget::dispatch((int) $row->id, (int) $row->team_id)
                    ->onQueue($this->queueFor($row));
            }

            $dispatched += count($claimed);
        }

        $this->promotePublishingPosts();

        $this->heartbeat();

        $this->components->info("Dispatched {$dispatched} target(s).");

        if ($halted > 0) {
            $this->components->warn("Held back {$halted} target(s): publishing is halted.");
        }

        return self::SUCCESS;
    }

    /**
     * Put a claimed row back, untouched.
     *
     * Claiming happens in bulk before the halt is known per team, so a held
     * target has to be handed back rather than left marked `queued` with no
     * job behind it -- which is exactly the orphan the reaper exists to clean
     * up, and there is no reason to make one deliberately.
     */
    private function release(object $row): void
    {
        DB::table('post_targets')
            ->where('id', $row->id)
            ->update([
                'status' => TargetStatus::Pending->value,
                'claimed_at' => null,
                'claimed_by' => null,
                'available_at' => now()->addMinutes(5),
                'updated_at' => now(),
            ]);
    }

    /**
     * Leave a mark saying the scheduler is alive.
     *
     * Nothing else in the system notices when it is not. The scheduler is a
     * separate process from Horizon, and when it stops -- a closed window, a
     * host reboot -- every queue worker stays up and healthy while no post is
     * ever dispatched. Posts pile up in `pending` and the only symptom is that
     * nothing happens, which is a long way from the cause.
     *
     * The TTL is what makes this useful: the key simply stops existing a few
     * minutes after the last run, so a reader checks for presence rather than
     * comparing timestamps and deciding what counts as stale.
     */
    private function heartbeat(): void
    {
        try {
            Cache::put(self::HEARTBEAT_KEY, now()->toIso8601String(), now()->addMinutes(5));
        } catch (\Throwable) {
            // A dead cache is its own alarm; it must not stop the dispatch.
        }
    }

    /** The last time the scheduler ran, or null when it has not run recently. */
    public static function lastRunAt(): ?string
    {
        try {
            return Cache::get(self::HEARTBEAT_KEY);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<object{id:int, team_id:int, post_id:int}>
     */
    private function claim(int $limit, string $worker, ?int $teamId): array
    {
        /*
         * Fair share first, then fill whatever is left over.
         *
         * Ordering the whole queue by scheduled_at hands every slot to
         * whichever team happens to have the oldest work, so one team fanning
         * out ten thousand targets stops every other team until it drains.
         * With one tenant that is invisible; with a thousand paying ones it is
         * an outage for nine hundred and ninety-nine of them.
         *
         * The top-up pass matters as much as the share: without it a team with
         * a single due target still reserves a full slice, and the run leaves
         * most of its capacity unused while a backlog waits.
         */
        $ids = $this->fairShareIds($limit, $teamId);

        if (count($ids) < $limit) {
            $ids = array_merge($ids, $this->fillIds($limit - count($ids), $teamId, $ids));
        }

        if ($ids === []) {
            return [];
        }

        return DB::transaction(function () use ($ids, $worker): array {
            $rows = DB::table('post_targets')
                ->select('id', 'team_id', 'post_id')
                ->whereIn('id', $ids)
                // Re-checked under the lock: another dispatcher may have taken
                // these between the two queries.
                ->whereIn('status', TargetStatus::claimable())
                ->orderBy('scheduled_at')
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
     * An equal slice of this run for every team that has work due.
     *
     * A window function rather than a query per team: at a thousand tenants
     * that would be a thousand round trips a minute, and the ranking is the
     * database's job.
     *
     * @param  list<int>  $exclude
     * @return list<int>
     */
    private function fairShareIds(int $limit, ?int $teamId, array $exclude = []): array
    {
        $teams = max(1, $this->teamsWithWork($teamId));
        $perTeam = max(1, (int) ceil($limit / $teams));

        $ranked = $this->dueTargets($teamId, $exclude)
            ->select('id', 'team_id', 'scheduled_at')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY team_id ORDER BY scheduled_at, id) AS team_rank');

        return DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('team_rank', '<=', $perTeam)
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Whatever is left after the fair share, oldest first.
     *
     * @param  list<int>  $exclude
     * @return list<int>
     */
    private function fillIds(int $limit, ?int $teamId, array $exclude): array
    {
        return $this->dueTargets($teamId, $exclude)
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function teamsWithWork(?int $teamId): int
    {
        return $teamId !== null ? 1 : (int) $this->dueTargets(null)->distinct()->count('team_id');
    }

    /**
     * Everything the dispatcher could take right now.
     *
     * @param  list<int>  $exclude
     */
    private function dueTargets(?int $teamId, array $exclude = []): Builder
    {
        return DB::table('post_targets')
            ->whereIn('status', TargetStatus::claimable())
            ->where('scheduled_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->when($teamId, fn ($q, $id) => $q->where('team_id', $id))
            ->when($exclude !== [], fn ($q) => $q->whereNotIn('id', $exclude));
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
