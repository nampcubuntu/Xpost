<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Post;
use App\Models\PostTarget;
use App\Models\XAccount;
use App\Models\XApp;
use App\Observers\PostTargetObserver;
use App\Observers\XAccountObserver;
use App\Policies\PostPolicy;
use App\Policies\XAccountPolicy;
use App\Policies\XAppPolicy;
use App\Services\Analytics\ClickHouseConnection;
use App\Services\X\XApiClientFactory;
use App\Services\X\XOAuth2Service;
use App\Services\X\XRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class XpostServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singletons: these hold no per-request state, and sharing them keeps
        // one Redis connection and one ClickHouse client per process.
        $this->app->singleton(XRateLimiter::class);
        $this->app->singleton(XOAuth2Service::class);
        $this->app->singleton(XApiClientFactory::class);

        $this->app->singleton(
            ClickHouseConnection::class,
            fn () => new ClickHouseConnection(config('xpost.clickhouse')),
        );
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureObservers();
        $this->configurePolicies();
        $this->configureRateLimiting();

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }

    private function configureModels(): void
    {
        // Fail loudly on a typo instead of silently ignoring the attribute.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Model::preventAccessingMissingAttributes(! $this->app->isProduction());

        Model::unguard(false);
    }

    private function configureObservers(): void
    {
        PostTarget::observe(PostTargetObserver::class);
        XAccount::observe(XAccountObserver::class);
    }

    private function configurePolicies(): void
    {
        Gate::policy(Post::class, PostPolicy::class);
        Gate::policy(XAccount::class, XAccountPolicy::class);
        Gate::policy(XApp::class, XAppPolicy::class);
    }

    /**
     * API throttles.
     *
     * The publish endpoint is deliberately tighter than the read endpoints: a
     * runaway script hitting "post" in a loop is expensive in X API budget,
     * not just in CPU.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('publish', fn (Request $request) => [
            Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()),
            Limit::perHour(200)->by($request->user()?->currentTeam?->id ?: $request->ip()),
        ]);

        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
