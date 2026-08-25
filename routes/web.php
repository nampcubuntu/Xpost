<?php

declare(strict_types=1);

use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\XOAuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
| The SPA owns client-side routing, so a single catch-all serves the shell and
| Vue Router takes it from there. Only three things need real server routes:
| the OAuth handshake, because X redirects a browser to it, and media files,
| because <img> and <video> ask for them directly.
*/

Route::middleware(['auth', 'team'])->group(function (): void {
    Route::get('x/oauth/{xApp}/redirect', [XOAuthController::class, 'redirect'])->name('x.oauth.redirect');

    /*
     * Media is served through the app rather than from a public disk.
     *
     * Uploads land on the private disk, so Storage::url() produced a path the
     * framework then refused with a 403. Moving them to the public disk would
     * have fixed the 403 by making every team's creatives readable to anyone
     * with the URL -- which contradicts the tenancy every other model enforces.
     * Route-model binding runs PostMedia through TeamScope, so this route is
     * team-scoped for free.
     */
    Route::get('media/{medium}/file', [MediaController::class, 'file'])->name('media.file');
});

Route::get('x/oauth/callback', [XOAuthController::class, 'callback'])->name('x.oauth.callback');

Route::view('/{any?}', 'app')
    ->where('any', '^(?!api|x/oauth|media|horizon|storage|up).*$')
    ->name('spa');
