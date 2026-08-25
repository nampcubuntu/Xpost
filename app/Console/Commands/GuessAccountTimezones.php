<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\XAccount;
use App\Services\X\TimezoneGuesser;
use App\Support\TeamContext;
use Illuminate\Console\Command;

/**
 * Fills in account timezones from the profile location and proxy country.
 *
 * Runs on demand rather than on a schedule: it is inference, the operator
 * should see what it decided, and a profile's location changes about as often
 * as the person moves house.
 */
class GuessAccountTimezones extends Command
{
    protected $signature = 'xpost:guess-timezones
                            {--team= : Limit to one team}
                            {--overwrite : Also replace timezones that were guessed before}
                            {--dry-run : Show what would change without saving}';

    protected $description = 'Infer account timezones from profile location and proxy country';

    public function handle(TimezoneGuesser $guesser): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $filled = 0;
        $skipped = 0;
        $byConfidence = ['high' => 0, 'low' => 0];

        TeamContext::runUnscoped(function () use ($guesser, $dryRun, &$filled, &$skipped, &$byConfidence): void {
            XAccount::withoutGlobalScopes()
                ->with('proxy:id,country')
                ->when($this->option('team'), fn ($q) => $q->where('team_id', (int) $this->option('team')))
                /*
                 * A timezone someone set by hand is never touched. --overwrite
                 * only reopens the ones a previous run guessed, so improving the
                 * rules cannot undo a human's correction.
                 */
                ->when(
                    ! $this->option('overwrite'),
                    fn ($q) => $q->whereNull('timezone'),
                    fn ($q) => $q->where(fn ($w) => $w->whereNull('timezone')->orWhere('timezone_source', '!=', 'manual')),
                )
                ->chunkById(500, function ($accounts) use ($guesser, $dryRun, &$filled, &$skipped, &$byConfidence): void {
                    foreach ($accounts as $account) {
                        $guess = $guesser->guess($account);

                        if ($guess === null) {
                            $skipped++;

                            continue;
                        }

                        $filled++;
                        $byConfidence[$guess['confidence']]++;

                        if ($this->output->isVerbose()) {
                            $this->components->twoColumnDetail(
                                '@'.$account->username.'  '.($account->location ?: '—'),
                                $guess['timezone'].'  ('.$guess['source'].', '.$guess['confidence'].')',
                            );
                        }

                        if (! $dryRun) {
                            $account->forceFill([
                                'timezone' => $guess['timezone'],
                                'timezone_source' => $guess['source'],
                            ])->save();
                        }
                    }
                });
        });

        $verb = $dryRun ? 'Would set' : 'Set';
        $this->components->info("{$verb} {$filled} timezone(s) — {$byConfidence['high']} chắc, {$byConfidence['low']} phỏng đoán. Không đoán được: {$skipped}.");

        if ($skipped > 0) {
            $this->components->warn('Số không đoán được sẽ dùng múi giờ của lịch. Đặt tay ở trang Tài khoản X.');
        }

        return self::SUCCESS;
    }
}
