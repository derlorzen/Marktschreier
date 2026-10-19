<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Eine Auswertung je Anfrage bzw. Job, damit mehrere Stellen dieselben Zahlen teilen.
        $this->app->scoped(\App\Services\Sales\SalesStats::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
