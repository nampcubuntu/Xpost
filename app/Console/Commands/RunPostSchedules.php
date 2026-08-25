<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\PostSchedule;
use App\Services\Publishing\ComposerService;
use App\Support\TeamContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Turns due schedule occurrences into ordinary posts.
 *
 * Everything downstream -- fan-out, the dispatcher, the rate limiter, the
 * publisher -- stays unaware that a schedule exists. A generated post is just a
 * post, which is why "3 posts a day to 1,000 accounts" needs no new publishing
 * path, only a thing that presses the button on time.
 */
class RunPostSchedules extends Command
{
    protected $signature = 'xpost:run-schedules
                            {--limit=200 : Maximum schedules to process in this run}
                            {--dry-run : Report what would be created without creating it}';

    protected $description = 'Create posts from recurring schedules that are due';

    public function handle(ComposerService $composer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $created = 0;
        $skipped = 0;

        TeamContext::runUnscoped(function () use ($composer, $dryRun, &$created, &$skipped): void {
            PostSchedule::withoutGlobalScopes()
                ->due()
                ->orderBy('next_run_at')
                ->limit((int) $this->option('limit'))
                ->get()
                ->each(function (PostSchedule $schedule) use ($composer, $dryRun, &$created, &$skipped): void {
                    $occurrence = CarbonImmutable::parse($schedule->next_run_at);

                    /*
                     * A slot missed by more than catch_up_minutes is dropped, not
                     * fired late. After an outage the alternative is every missed
                     * occurrence going out at once, which for 1,000 accounts is
                     * both a spam signal and a surprise bill.
                     */
                    if ($occurrence->addMinutes($schedule->catch_up_minutes)->isPast()) {
                        $this->components->warn(sprintf(
                            'Skipped [%s] slot %s -- more than %dm late.',
                            $schedule->name,
                            $occurrence->format('Y-m-d H:i'),
                            $schedule->catch_up_minutes,
                        ));

                        $schedule->refreshNextRun(now());
                        $skipped++;

                        return;
                    }

                    if ($dryRun) {
                        $this->components->twoColumnDetail(
                            $schedule->name,
                            $occurrence->format('Y-m-d H:i'),
                        );
                        $created++;

                        return;
                    }

                    $this->generate($composer, $schedule, $occurrence)
                        ? $created++
                        : $skipped++;
                });
        });

        $verb = $dryRun ? 'Would create' : 'Created';
        $this->components->info("{$verb} {$created} post(s), skipped {$skipped}.");

        return self::SUCCESS;
    }

    private function generate(ComposerService $composer, PostSchedule $schedule, CarbonImmutable $occurrence): bool
    {
        return TeamContext::runAs($schedule->team_id, function () use ($composer, $schedule, $occurrence): bool {
            try {
                $payload = array_merge($schedule->template, [
                    'campaign_id' => $schedule->campaign_id,
                    'timezone' => $schedule->timezone,
                    'scheduled_at' => $occurrence->toDateTimeString(),
                    // Every account in the audience fires at the same instant
                    // unless the template asked for something else.
                    'schedule_mode' => $schedule->template['schedule_mode'] ?? 'fixed_time',
                    'name' => $schedule->name.' — '.$occurrence->format('d/m H:i'),
                ]);

                $post = $composer->create($payload, (int) $schedule->created_by);

                $post->forceFill([
                    'post_schedule_id' => $schedule->id,
                    'occurrence_at' => $occurrence,
                ])->save();

                $composer->schedule($post);

                $schedule->forceFill([
                    'last_run_at' => now(),
                    'runs_count' => $schedule->runs_count + 1,
                    'last_error' => null,
                ])->save();

                $schedule->refreshNextRun($occurrence);

                $this->components->twoColumnDetail(
                    $schedule->name,
                    "post #{$post->id} at ".$occurrence->format('H:i'),
                );

                return true;
            } catch (QueryException $e) {
                // The unique (post_schedule_id, occurrence_at) index means a
                // duplicate here is a re-run, not a failure.
                if (str_contains($e->getMessage(), 'schedule_occurrence_unique')) {
                    $schedule->refreshNextRun($occurrence);

                    return false;
                }

                return $this->recordFailure($schedule, $occurrence, $e);
            } catch (\Throwable $e) {
                return $this->recordFailure($schedule, $occurrence, $e);
            }
        });
    }

    private function recordFailure(PostSchedule $schedule, CarbonImmutable $occurrence, \Throwable $e): bool
    {
        Log::channel('xpost')->error('Schedule run failed', [
            'schedule_id' => $schedule->id,
            'occurrence' => $occurrence->toDateTimeString(),
            'error' => $e->getMessage(),
        ]);

        $schedule->forceFill([
            'last_error' => mb_substr($e->getMessage(), 0, 2000),
        ])->save();

        // Move on rather than retrying the same broken slot every minute.
        $schedule->refreshNextRun($occurrence);

        $this->components->error("[{$schedule->name}] ".$e->getMessage());

        return false;
    }
}
