<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ShortLink;
use App\Models\Team;
use App\Services\Links\CloudflareKvClient;
use App\Support\TeamContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Bring the local index in step with the KV namespace.
 *
 * Needed twice. Once to seed it, for the keys that were there before the index
 * existed. And afterwards whenever something writes to the namespace that is
 * not this application -- the Cloudflare dashboard, a script, another install
 * against the same namespace.
 *
 * Reads the whole namespace, which is the expensive thing the list page no
 * longer does on every load: 642ms per thousand keys, once, from a command
 * rather than from someone waiting for a table.
 */
class BackfillShortLinks extends Command
{
    protected $signature = 'xpost:backfill-links
                            {--team= : One team, rather than every team with a namespace}
                            {--prune : Also drop rows for keys the namespace no longer holds}';

    protected $description = 'Index the short links held in Cloudflare KV so the list can order them';

    public function handle(CloudflareKvClient $kv): int
    {
        $teams = Team::query()
            ->whereNotNull('cloudflare_kv_namespace_id')
            ->when($this->option('team'), fn ($q, $id) => $q->whereKey($id))
            ->get(['id', 'name']);

        if ($teams->isEmpty()) {
            $this->components->warn('No team has a KV namespace configured.');

            return self::SUCCESS;
        }

        foreach ($teams as $team) {
            TeamContext::runAs($team->id, function () use ($team, $kv): void {
                try {
                    // No page cap: this is the one place reading everything is
                    // the point, and it runs once rather than per page view.
                    $result = $kv->listAllKeys(null, maxPages: 1000);
                } catch (Throwable $e) {
                    $this->components->error("[{$team->name}] {$e->getMessage()}");

                    return;
                }

                $this->index($team->id, $result['keys']);
            });
        }

        return self::SUCCESS;
    }

    /** @param  list<array{name: string, created_at: ?int}>  $keys */
    private function index(int $teamId, array $keys): void
    {
        $seen = [];

        foreach (array_chunk($keys, 500) as $chunk) {
            $rows = [];

            foreach ($chunk as $key) {
                $seen[] = $key['name'];

                $rows[] = [
                    'team_id' => $teamId,
                    'key' => $key['name'],
                    /*
                     * Whatever the metadata knows, which for a key written
                     * before any of this existed is nothing. Left null rather
                     * than stamped with today: an invented date would sort a
                     * year-old link to the top of the list.
                     */
                    'created_at' => $key['created_at']
                        ? date('Y-m-d H:i:s', $key['created_at'])
                        : null,
                    'updated_at' => now(),
                ];
            }

            ShortLink::query()->upsert($rows, ['team_id', 'key'], ['updated_at']);
        }

        $pruned = 0;

        if ($this->option('prune')) {
            // Rows for keys the namespace no longer holds -- deleted from the
            // Cloudflare dashboard, or by an install that does not index.
            $pruned = ShortLink::query()->whereNotIn('key', $seen ?: ['']);
            $pruned = $pruned->delete();
        }

        $this->components->twoColumnDetail(
            'team '.$teamId,
            count($keys).' key indexed'.($pruned ? ", {$pruned} pruned" : ''),
        );
    }
}
