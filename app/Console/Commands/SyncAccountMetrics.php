<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncTweetMetrics;
use App\Models\XAccount;
use Illuminate\Console\Command;

class SyncAccountMetrics extends Command
{
    protected $signature = 'xpost:sync-metrics
                            {--hours=72 : Look back this far for published tweets}
                            {--limit=1000 : Maximum accounts per run}
                            {--team= : Restrict to one team}';

    protected $description = 'Queue engagement metric syncs for accounts with recent posts';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $teamId = $this->option('team') ? (int) $this->option('team') : null;
        $queued = 0;

        XAccount::withoutGlobalScopes()
            ->publishable()
            ->when($teamId, fn ($q, $id) => $q->where('team_id', $id))
            ->whereNotNull('last_posted_at')
            ->where('last_posted_at', '>=', now()->subHours($hours))
            ->orderBy('last_synced_at')
            ->limit((int) $this->option('limit'))
            ->chunkById(200, function ($accounts) use (&$queued, $hours): void {
                foreach ($accounts as $account) {
                    SyncTweetMetrics::dispatch($account->id, $account->team_id, $hours)
                        // Spread the reads so the metrics queue does not starve
                        // the publish queue of API budget.
                        ->delay(now()->addSeconds(random_int(0, 600)));
                    $queued++;
                }
            });

        $this->components->info("Queued {$queued} metric sync(es).");

        return self::SUCCESS;
    }
}
