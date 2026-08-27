<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RefreshAccountToken;
use App\Models\XAccount;
use Illuminate\Console\Command;

/**
 * Refreshes OAuth 2.0 tokens before they lapse.
 *
 * Doing this on a schedule rather than lazily on the publish path matters at
 * this scale: a campaign that starts at 09:00 would otherwise fire thousands of
 * simultaneous refreshes at 09:00 as well.
 */
class RefreshExpiringTokens extends Command
{
    protected $signature = 'xpost:refresh-tokens
                            {--within=1800 : Refresh tokens expiring within this many seconds}
                            {--limit=2000 : Maximum accounts per run}
                            {--work-within=60 : Only accounts with work due within this many minutes; 0 for all}';

    protected $description = 'Refresh OAuth 2.0 access tokens that are about to expire';

    public function handle(): int
    {
        $within = (int) $this->option('within');
        $limit = (int) $this->option('limit');
        $workWithin = (int) $this->option('work-within');
        $queued = 0;

        XAccount::withoutGlobalScopes()
            ->needingTokenRefresh($within)
            /*
             * Only accounts about to publish.
             *
             * A token lasts two hours, so warming every account that holds one
             * means refreshing the whole pool twelve times a day whether or not
             * it posts. freshAccessToken() already refreshes on use, so this
             * run exists purely to keep that off the publish path -- worth
             * doing for accounts with work coming, pure waste for the rest.
             *
             * --work-within=0 restores the old sweep, for a pool small enough
             * that warming all of it costs nothing.
             */
            ->when($workWithin > 0, fn ($q) => $q->withWorkDueWithin($workWithin))
            ->orderBy('token_expires_at')
            ->limit($limit)
            ->chunkById(200, function ($accounts) use (&$queued): void {
                foreach ($accounts as $account) {
                    RefreshAccountToken::dispatch($account->id, $account->team_id);
                    $queued++;
                }
            });

        $this->components->info("Queued {$queued} token refresh(es).");

        return self::SUCCESS;
    }
}
