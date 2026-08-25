<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TeamContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the caller's active team into Context before anything touches a model.
 *
 * Every tenant-owned model is scoped by TeamScope, which reads from here, so
 * this middleware is what stands between one customer and another's accounts.
 * An authenticated user with no team gets a 409 rather than an unscoped query.
 */
class ResolveTeamContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $teamId = $user->current_team_id;

        // Allow an explicit override, but only to a team the user belongs to.
        if ($header = $request->header('X-Team-Id')) {
            $requested = (int) $header;

            if (! $user->belongsToTeam($requested)) {
                abort(403, 'You do not have access to that team.');
            }

            $teamId = $requested;
        }

        if ($teamId === null) {
            abort(409, 'No active team. Create or join a team first.');
        }

        TeamContext::set((int) $teamId);

        return $next($request);
    }
}
