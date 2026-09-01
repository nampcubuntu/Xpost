<?php

use App\Exceptions\XRateLimitException;
use App\Http\Middleware\ResolveTeamContext;
use App\Support\TeamContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Anything terminating TLS in front of us -- a Cloudflare tunnel, a load
         * balancer, ngrok/localhost.run during local OAuth testing -- forwards
         * plain HTTP inwards. Without trusting the forwarded headers Laravel
         * believes the request was http and renders every asset URL as
         * `http://…`, which the browser then blocks as mixed content on an
         * https page. The symptom is a completely blank SPA.
         *
         * `*` because the proxy's address is not knowable ahead of time for any
         * of those. That is the standard trade-off: only ever run this behind a
         * proxy that overwrites X-Forwarded-*, never on a directly exposed port,
         * or a client could spoof its own scheme and address.
         */
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_AWS_ELB);

        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        // The login screen is a SPA route, so there is no server-side `login`
        // route for Authenticate to resolve -- without this a guest hitting a
        // protected web route gets a RouteNotFoundException instead of a redirect.
        $middleware->redirectGuestsTo('/login');

        $middleware->alias([
            'team' => ResolveTeamContext::class,
        ]);

        // Health checks and the OAuth callback are hit by machines, not browsers.
        $middleware->validateCsrfTokens(except: [
            'x/oauth/callback',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // X API failures are expected traffic, not incidents -- keep them out
        // of the alerting channel but still in the xpost log.
        $exceptions->dontReport([
            XRateLimitException::class,
        ]);

        $exceptions->context(fn () => array_filter([
            'team_id' => TeamContext::id(),
        ]));
    })->create();
