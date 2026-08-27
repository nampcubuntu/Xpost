<?php

namespace App\Providers;

use App\Services\Links\CloudflareKvClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * Built from config rather than autowired: the constructor takes plain
         * strings, which the container cannot resolve on its own.
         *
         * bind, not singleton -- the credentials come from config, and a cached
         * instance would keep serving the values it was built with after they
         * change.
         */
        $this->app->bind(CloudflareKvClient::class, fn () => CloudflareKvClient::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
