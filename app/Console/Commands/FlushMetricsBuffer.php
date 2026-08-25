<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\MetricsRecorder;
use Illuminate\Console\Command;

class FlushMetricsBuffer extends Command
{
    protected $signature = 'xpost:flush-metrics {--max=5000 : Rows per flush}';

    protected $description = 'Drain buffered publish events from Redis into ClickHouse';

    public function handle(MetricsRecorder $recorder): int
    {
        $flushed = $recorder->flush((int) $this->option('max'));
        $remaining = $recorder->bufferDepth();

        $this->components->info("Flushed {$flushed} event(s), {$remaining} still buffered.");

        return self::SUCCESS;
    }
}
