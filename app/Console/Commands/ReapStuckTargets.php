<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TargetStatus;
use App\Models\PostTarget;
use Illuminate\Console\Command;

/**
 * Returns targets that were claimed but never resolved.
 *
 * A worker can die between the claim commit and the publish -- OOM, deploy,
 * spot instance reclaimed. Those rows sit in `queued`/`publishing` forever
 * unless something puts them back. The lease window is deliberately longer than
 * the job timeout so a slow-but-alive worker is never reaped out from under.
 */
class ReapStuckTargets extends Command
{
    protected $signature = 'xpost:reap
                            {--minutes=30 : Age of the claim before it is considered lost}
                            {--max-attempts=5 : Give up on the target past this many attempts}';

    protected $description = 'Return abandoned post targets to the pending pool';

    public function handle(): int
    {
        $cutoff = now()->subMinutes(max(1, (int) $this->option('minutes')));
        $maxAttempts = (int) $this->option('max-attempts');

        $requeued = PostTarget::withoutGlobalScopes()
            ->whereIn('status', [TargetStatus::Queued->value, TargetStatus::Publishing->value])
            ->where('claimed_at', '<', $cutoff)
            ->where('attempts', '<', $maxAttempts)
            ->update([
                'status' => TargetStatus::Pending->value,
                'claimed_at' => null,
                'claimed_by' => null,
                'available_at' => now(),
                'error_code' => 'reclaimed',
                'updated_at' => now(),
            ]);

        $abandoned = PostTarget::withoutGlobalScopes()
            ->whereIn('status', [TargetStatus::Queued->value, TargetStatus::Publishing->value])
            ->where('claimed_at', '<', $cutoff)
            ->where('attempts', '>=', $maxAttempts)
            ->update([
                'status' => TargetStatus::Failed->value,
                'error_code' => 'abandoned',
                'error_message' => 'Exceeded the retry budget after repeated worker loss.',
                'updated_at' => now(),
            ]);

        $this->components->info("Requeued {$requeued}, abandoned {$abandoned}.");

        return self::SUCCESS;
    }
}
