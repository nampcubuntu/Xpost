<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AccountGroupController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ComposerController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\PostScheduleController;
use App\Http\Controllers\Api\ProxyController;
use App\Http\Controllers\Api\TimezoneController;
use App\Http\Controllers\Api\XAppController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Xpost API
|--------------------------------------------------------------------------
| Consumed by the Vue SPA over Sanctum cookie auth, and by automation clients
| over bearer tokens. Both hit the same routes: `auth:sanctum` accepts either.
|
| The `team` middleware resolves the active tenant into Context; every route
| behind it queries through TeamScope and cannot see another team's rows.
*/

Route::prefix('v1')->group(function (): void {

    Route::post('login', [AuthController::class, 'login'])->middleware('guest');
    Route::post('register', [AuthController::class, 'register'])->middleware('guest');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('teams/switch', [AuthController::class, 'switchTeam']);
        Route::post('tokens', [AuthController::class, 'issueToken']);

        Route::middleware('team')->group(function (): void {

            // ----- Composer helpers (read-only, safe to poll) -----
            Route::prefix('composer')->group(function (): void {
                Route::get('options', [ComposerController::class, 'options']);
                Route::post('measure', [ComposerController::class, 'measure']);
                Route::post('samples', [ComposerController::class, 'sampleVariants']);
                Route::post('estimate', [ComposerController::class, 'estimate']);
            });

            // ----- Posts -----
            Route::apiResource('posts', PostController::class);
            Route::get('posts/{post}/preview', [PostController::class, 'preview']);
            Route::get('posts/{post}/targets', [PostController::class, 'targets']);
            Route::post('posts/{post}/cancel', [PostController::class, 'cancel']);
            Route::post('posts/{post}/retry-failed', [PostController::class, 'retryFailed']);

            // Lifting a halt is a human saying the balance is back; Xpost
            // cannot read it, and X exposes no endpoint that would.
            Route::post('publishing/resume', [PostController::class, 'resume']);

            // Publishing spends API budget, so it gets its own tighter throttle.
            Route::post('posts/{post}/publish', [PostController::class, 'publish'])
                ->middleware('throttle:publish');

            // The account pool is global, so every time picker needs the whole list.
            Route::get('timezones', [TimezoneController::class, 'index']);

            // ----- Recurring schedules -----
            Route::post('schedules/preview', [PostScheduleController::class, 'previewDraft']);
            Route::apiResource('schedules', PostScheduleController::class)
                ->parameters(['schedules' => 'postSchedule']);
            Route::post('schedules/{postSchedule}/toggle', [PostScheduleController::class, 'toggle']);
            Route::get('schedules/{postSchedule}/preview', [PostScheduleController::class, 'preview']);

            // ----- Accounts -----
            Route::get('accounts/stats', [AccountController::class, 'stats']);
            Route::post('accounts/bulk', [AccountController::class, 'bulk']);
            Route::post('accounts/rebalance', [AccountController::class, 'rebalance']);
            Route::apiResource('accounts', AccountController::class)->except(['store']);

            Route::apiResource('account-groups', AccountGroupController::class)->except(['show']);
            Route::post('account-groups/{account_group}/sync', [AccountGroupController::class, 'sync']);

            // ----- Infrastructure -----
            Route::apiResource('apps', XAppController::class)->parameters(['apps' => 'xApp']);
            Route::get('apps/{xApp}/authorize-url', [XAppController::class, 'authorizeUrl']);
            Route::get('apps/{xApp}/authorize-url-oauth1', [XAppController::class, 'authorizeUrlOAuth1']);

            Route::apiResource('proxies', ProxyController::class)->except(['show']);
            Route::post('proxies/{proxy}/check', [ProxyController::class, 'check']);

            // ----- Media -----
            Route::apiResource('media', MediaController::class)
                ->parameters(['media' => 'medium'])
                ->except(['show'])
                ->middleware(['throttle:uploads']);

            // ----- Analytics -----
            Route::get('analytics/overview', [AnalyticsController::class, 'overview']);
            Route::get('analytics/accounts', [AnalyticsController::class, 'accounts']);
            Route::get('analytics/posts/{post}', [AnalyticsController::class, 'post']);
        });
    });
});
