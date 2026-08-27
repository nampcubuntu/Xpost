<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PostMedia;
use App\Support\TeamContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Move stored media from one disk to another.
 *
 * Written before it is needed, because the day it is needed is the day a second
 * host goes live and half the pool's videos are unreachable from it. Switching
 * FILESYSTEM_DISK only changes where new uploads land; everything already
 * stored stays on the disk its row names, and stays invisible to the new host.
 *
 * Copy, verify, update the row, then delete. In that order: a crash at any
 * point leaves the file readable from somewhere, which is the only property
 * worth having in a command that moves a customer's data.
 */
class MigrateMediaDisk extends Command
{
    protected $signature = 'xpost:migrate-media
                            {--from=local : Disk to move files off}
                            {--to=r2 : Disk to move files onto}
                            {--limit=1000 : Files to move in this run}
                            {--keep-source : Copy without deleting the original}
                            {--pretend : Report what would move and change nothing}';

    protected $description = 'Copy stored media onto another disk and repoint the rows';

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');

        if ($from === $to) {
            $this->components->error('--from and --to are the same disk.');

            return self::FAILURE;
        }

        // Cross-tenant by nature: this moves storage, not a team's content.
        $media = TeamContext::runUnscoped(fn () => PostMedia::query()
            ->withoutGlobalScopes()
            ->where('disk', $from)
            ->limit(max(1, (int) $this->option('limit')))
            ->get());

        if ($media->isEmpty()) {
            $this->components->info("Nothing left on [{$from}].");

            return self::SUCCESS;
        }

        $source = Storage::disk($from);
        $destination = Storage::disk($to);

        $moved = 0;
        $missing = 0;

        foreach ($media as $item) {
            if (! $source->exists($item->path)) {
                // Already gone. Saying so is more useful than failing the run:
                // a half-finished earlier attempt looks exactly like this.
                $this->components->warn("missing on [{$from}]: {$item->path}");
                $missing++;

                continue;
            }

            if ($this->option('pretend')) {
                $this->line("  would move {$item->path}");
                $moved++;

                continue;
            }

            $stream = $source->readStream($item->path);
            $destination->writeStream($item->path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            /*
             * Verified before the row moves. A short write on a remote disk
             * returns no error, and repointing the row first would leave a
             * truncated file as the only copy.
             */
            if ($destination->size($item->path) !== $source->size($item->path)) {
                $this->components->error("size mismatch, left in place: {$item->path}");

                continue;
            }

            $item->forceFill(['disk' => $to])->save();

            if (! $this->option('keep-source')) {
                $source->delete($item->path);
            }

            $moved++;
        }

        $this->components->info(
            $this->option('pretend')
                ? "Would move {$moved} file(s) from [{$from}] to [{$to}]."
                : "Moved {$moved} file(s) from [{$from}] to [{$to}]."
        );

        if ($missing > 0) {
            $this->components->warn("{$missing} row(s) pointed at files that were not there.");
        }

        return self::SUCCESS;
    }
}
