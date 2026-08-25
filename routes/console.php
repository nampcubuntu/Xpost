<?php

declare(strict_types=1);

use App\Console\Commands\DispatchDueTargets;
use App\Console\Commands\FlushMetricsBuffer;
use App\Console\Commands\ReapStuckTargets;
use App\Console\Commands\RefreshExpiringTokens;
use App\Console\Commands\RunPostSchedules;
use App\Console\Commands\SyncAccountMetrics;
use App\Models\XApp;
use App\Models\XOAuthState;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Xpost schedule
|--------------------------------------------------------------------------
| Everything below runs under a single `php artisan schedule:work` (or the
| crontab entry in docs/DEPLOY.md).
|
| withoutOverlapping is on every entry that touches shared rows -- on a
| multi-node deployment the scheduler runs on each node, and two dispatchers
| claiming at once is fine, but two reapers racing is not.
*/

// The publishing loop. Sub-minute cadence so a "post now" feels immediate.
Schedule::command(DispatchDueTargets::class, ['--limit' => config('xpost.publishing.dispatch_limit_per_run')])
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground()
    ->onOneServer();

// Recurring schedules become ordinary posts. Every minute, because a slot
// missed by more than the schedule's catch-up window is dropped rather than
// fired late.
Schedule::command(RunPostSchedules::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Recover work lost to dead workers.
Schedule::command(ReapStuckTargets::class, ['--minutes' => config('xpost.publishing.claim_lease_minutes')])
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Keep OAuth 2.0 tokens ahead of expiry rather than refreshing on the hot path.
Schedule::command(RefreshExpiringTokens::class)
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Analytics.
Schedule::command(FlushMetricsBuffer::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(SyncAccountMetrics::class)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// Housekeeping.
Schedule::command('model:prune')->daily();
Schedule::command('queue:prune-batches --hours=48 --unfinished=72')->daily();
Schedule::command('queue:prune-failed --hours=336')->daily();
Schedule::command('horizon:snapshot')->everyFiveMinutes();

Schedule::call(function (): void {
    XOAuthState::withoutGlobalScopes()
        ->where('expires_at', '<', now()->subDay())
        ->delete();
})->daily()->name('prune-oauth-states')->onOneServer();

// Monthly API budget reset, aligned to the billing period rather than the calendar.
Schedule::call(function (): void {
    XApp::withoutGlobalScopes()
        ->whereNotNull('usage_period_started_on')
        ->where('usage_period_started_on', '<=', now()->subMonth())
        ->update([
            'monthly_posts_used' => 0,
            'usage_period_started_on' => now()->toDateString(),
        ]);
})->dailyAt('00:05')->name('reset-app-budgets')->onOneServer();
