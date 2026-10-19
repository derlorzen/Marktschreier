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
        \App\Services\Alerts\AlertChecks::register('oversell', function (\App\Services\Alerts\AlertService $alerts) {
            $keys = [];
            foreach (app(\App\Services\Stock\StockOverview::class)->oversold() as $row) {
                $v = $row['variant'];
                $keys[] = $key = 'oversell:'.$v->id;
                $alerts->raise($key, 'oversell', 'bad', "Überverkaufsgefahr: {$v->product->title}".($v->label() !== 'Standard' ? " ({$v->label()})" : ''), implode('. ', $row['warnings']).'.', '/bestand?filter=warn');
            }
            $alerts->resolveMissing('oversell', $keys);
        });
        \App\Services\Alerts\AlertChecks::register('buybox', function (\App\Services\Alerts\AlertService $alerts) {
            $keys = [];
            foreach (\App\Models\PriceWatch::with('variant.product')->where('active', true)->get() as $w) {
                if (($w->last_result['won'] ?? true) || ! $w->suggested_price) {
                    continue;
                }
                $keys[] = $key = 'buybox:'.$w->id;
                $alerts->raise($key, 'buybox', 'warn', "Buy Box verloren: {$w->variant->product->title}",
                    'Vorschlag: '.\App\Support\Format::money($w->suggested_price).($w->last_result['note'] ?? null ? '. '.$w->last_result['note'] : '').'.', '/preiswaechter');
            }
            $alerts->resolveMissing('buybox', $keys);
        });
        \Illuminate\Support\Facades\Gate::define('admin', fn (\App\Models\User $user) => $user->isAdmin() && $user->active);
        //
    }
}
