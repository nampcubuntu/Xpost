<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ImportAccountChunk;
use App\Models\AccountImportBatch;
use App\Support\TeamContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * Bulk-import accounts from a CSV of OAuth 1.0a user tokens.
 *
 * The file is streamed, not loaded: a 10k-row export is small, but the same
 * command should not fall over on a 200k-row one.
 */
class ImportAccounts extends Command
{
    protected $signature = 'xpost:import-accounts
                            {file : Path to the CSV file}
                            {--team= : Team id to import into}
                            {--chunk=100 : Rows per queued job}
                            {--no-verify : Skip the live GET /2/users/me check}';

    protected $description = 'Import X accounts in bulk from a CSV of user tokens';

    public function handle(): int
    {
        $file = (string) $this->argument('file');

        if (! is_readable($file)) {
            $this->components->error("Cannot read [{$file}].");

            return self::FAILURE;
        }

        $teamId = (int) ($this->option('team') ?: 0);

        if ($teamId <= 0) {
            $this->components->error('--team is required.');

            return self::FAILURE;
        }

        $handle = fopen($file, 'rb');
        $header = fgetcsv($handle);

        if ($header === false) {
            $this->components->error('The file is empty.');
            fclose($handle);

            return self::FAILURE;
        }

        $header = array_map(static fn ($h) => strtolower(trim((string) $h)), $header);

        $required = ['username', 'access_token', 'token_secret'];
        $missing = array_diff($required, $header);

        if ($missing !== []) {
            $this->components->error('Missing column(s): '.implode(', ', $missing));
            fclose($handle);

            return self::FAILURE;
        }

        $batch = TeamContext::runAs($teamId, fn () => AccountImportBatch::create([
            'team_id' => $teamId,
            'filename' => basename($file),
            'path' => $file,
            'status' => 'processing',
        ]));

        $chunkSize = max(1, (int) $this->option('chunk'));
        $verify = ! $this->option('no-verify');

        $jobs = [];
        $buffer = [];
        $total = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }

            $buffer[] = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), ''));
            $total++;

            if (count($buffer) >= $chunkSize) {
                $jobs[] = new ImportAccountChunk($buffer, $teamId, $batch->id, $verify);
                $buffer = [];
            }
        }

        fclose($handle);

        if ($buffer !== []) {
            $jobs[] = new ImportAccountChunk($buffer, $teamId, $batch->id, $verify);
        }

        if ($jobs === []) {
            $this->components->warn('No data rows found.');

            return self::SUCCESS;
        }

        $batch->forceFill(['rows_total' => $total])->save();

        $dispatched = Bus::batch($jobs)
            ->name("xpost:import:{$batch->id}")
            ->allowFailures()
            ->onQueue('imports')
            ->dispatch();

        $batch->forceFill(['job_batch_id' => $dispatched->id])->save();

        $this->components->info("Queued {$total} row(s) across ".count($jobs).' job(s).');
        $this->components->twoColumnDetail('Batch id', (string) $batch->id);
        $this->components->twoColumnDetail('Progress', 'php artisan queue:monitor / Horizon dashboard');

        return self::SUCCESS;
    }
}
