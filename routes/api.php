<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AccountGroupController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ComposerController;
use App\Http\Controllers\Api\LinkController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\MemberController;
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
            Route::put('publishing/budget', [PostController::class, 'updateBudget']);

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

            // Asked live rather than stored: one Ads API call per account, so
            // it happens when someone opens the picker, never per list row.
            Route::get('accounts/{account}/ads-accounts', [AccountController::class, 'adsAccounts']);
            Route::apiResource('accounts', AccountController::class)->except(['store']);

            Route::apiResource('account-groups', AccountGroupController::class)->except(['show']);
            Route::post('account-groups/{account_group}/sync', [AccountGroupController::class, 'sync']);

            /*
             * Team membership. Bound to `member` rather than `user` so route
             * model binding cannot be confused with the authenticated caller.
             */
            Route::get('members', [MemberController::class, 'index']);
            Route::post('members', [MemberController::class, 'store']);
            Route::put('members/{member}', [MemberController::class, 'update']);
            Route::delete('members/{member}', [MemberController::class, 'destroy']);

            // ----- Infrastructure -----
            Route::apiResource('apps', XAppController::class)->parameters(['apps' => 'xApp']);
            Route::get('apps/{xApp}/authorize-url', [XAppController::class, 'authorizeUrl']);
            Route::get('apps/{xApp}/authorize-url-oauth1', [XAppController::class, 'authorizeUrlOAuth1']);

            /*
             * Short links, held in Cloudflare KV rather than this database --
             * a Worker reads them, so Xpost is one writer of someone else's
             * store and never mirrors it locally.
             */
            Route::get('links', [LinkController::class, 'index']);
            Route::post('links', [LinkController::class, 'store']);
            Route::post('links/bulk', [LinkController::class, 'bulk']);
            // Separate from listing because it writes: opening a page should
            // not mint TinyURL codes for every row it happens to show.
            Route::post('links/shorten', [LinkController::class, 'shorten']);
            Route::delete('links', [LinkController::class, 'destroy']);

            /*
             * The hosts a code can be served from. Xpost's own data, not
             * Cloudflare's: every domain routing to the same Worker resolves
             * every code, so this is a choice, not a property of a key.
             */
            Route::get('link-domains', [LinkController::class, 'domains']);
            Route::put('link-namespace', [LinkController::class, 'updateNamespace']);
            Route::post('link-domains', [LinkController::class, 'storeDomain']);
            Route::put('link-domains/{domain}', [LinkController::class, 'updateDomain']);
            Route::delete('link-domains/{domain}', [LinkController::class, 'destroyDomain']);

            Route::apiResource('proxies', ProxyController::class)->except(['show']);
            Route::post('proxies/bulk', [ProxyController::class, 'bulk']);
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
