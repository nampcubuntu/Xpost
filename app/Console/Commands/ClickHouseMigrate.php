<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\ClickHouseConnection;
use App\Services\Analytics\ClickHouseSchema;
use Illuminate\Console\Command;

class ClickHouseMigrate extends Command
{
    protected $signature = 'xpost:clickhouse-migrate {--fresh : Drop the analytics tables first}';

    protected $description = 'Create the ClickHouse analytics tables and materialised views';

    public function handle(ClickHouseConnection $clickhouse): int
    {
        if (! $clickhouse->enabled()) {
            $this->components->warn('ClickHouse is disabled. Set CLICKHOUSE_ENABLED=true to use analytics.');

            return self::SUCCESS;
        }

        // Create the database before the connectivity check: the check itself
        // used to fail on a healthy server simply because the database was
        // not there yet.
        $clickhouse->ensureDatabase();

        if (! $clickhouse->ping()) {
            $this->components->error('Cannot reach ClickHouse. Check CLICKHOUSE_HOST / CLICKHOUSE_PORT.');

            return self::FAILURE;
        }

        if ($this->option('fresh') && $this->confirm('Drop all Xpost analytics tables?', false)) {
            foreach (['xpost_daily_stats_mv', 'xpost_daily_stats', 'xpost_tweet_metrics', 'xpost_publish_events'] as $table) {
                $clickhouse->statement("DROP TABLE IF EXISTS {$table}");
                $this->components->twoColumnDetail("drop {$table}", '<fg=yellow>DONE</>');
            }
        }

        foreach (ClickHouseSchema::statements((string) config('xpost.clickhouse.database')) as $sql) {
            $ok = $clickhouse->statement($sql);
            $label = str($sql)->squish()->limit(60)->toString();

            $this->components->twoColumnDetail($label, $ok ? '<fg=green>DONE</>' : '<fg=red>FAIL</>');
        }

        return self::SUCCESS;
    }
}
