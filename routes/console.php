<?php

use App\Jobs\ImportShopifyProducts;
use App\Jobs\SyncBillbee;
use App\Models\Setting;
use App\Models\User;
use App\Services\Billbee\BillbeeClient;
use App\Services\Billbee\StockAndCostSync;
use App\Services\Shopify\ProductImporter;
use App\Services\Shopify\ShopifyClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;

Artisan::command('marktschreier:user {email} {name?} {--password=}', function (string $email, ?string $name = null) {
    $password = $this->option('password') ?: $this->secret('Passwort');
    if (! $password || strlen($password) < 10) {
        $this->error('Das Passwort muss mindestens 10 Zeichen haben.');

        return 1;
    }
    $user = User::updateOrCreate(['email' => $email], ['name' => $name ?: $email, 'password' => Hash::make($password)]);
    $this->info(($user->wasRecentlyCreated ? 'Angelegt: ' : 'Aktualisiert: ').$user->email);

    return 0;
})->purpose('Benutzer anlegen oder Passwort setzen');

Artisan::command('marktschreier:import {--full : Alle Artikel statt nur geänderte}', function () {
    $since = $this->option('full') ? null : \App\Models\SyncRun::lastSuccessful(\App\Models\SyncRun::TYPE_SHOPIFY_IMPORT)?->started_at?->copy()->subMinutes(5);
    $this->info($since ? 'Import der Änderungen seit '.$since->format('d.m.Y H:i') : 'Vollständiger Import');
    $stats = app(ProductImporter::class)->run($since);
    $this->table(['Wert', 'Anzahl'], collect($stats)->map(fn ($v, $k) => [$k, is_array($v) ? count($v) : $v])->values());
})->purpose('Artikel aus Shopify importieren');

Artisan::command('marktschreier:orders {--rematch : Vorhandene Bestellungen neu Kanälen und Artikeln zuordnen}', function () {
    if ($this->option('rematch')) {
        $this->info(app(\App\Services\Billbee\OrderSync::class)->rematch().' Zuordnungen geändert');

        return;
    }
    $stats = app(\App\Services\Billbee\OrderSync::class)->run();
    $this->table(['Wert', 'Anzahl'], collect($stats)->map(fn ($v, $k) => [$k, is_array($v) ? count($v) : $v])->values());
})->purpose('Verkäufe aus Billbee lesen');

Artisan::command('marktschreier:billbee', function () {
    $stats = app(StockAndCostSync::class)->run();
    $this->table(['Wert', 'Anzahl'], collect($stats)->map(fn ($v, $k) => [$k, is_array($v) ? count($v) : $v])->values());
})->purpose('EK und Bestand aus Billbee lesen');

$every = fn (int $minutes) => $minutes >= 60 ? '0 */'.max(1, intdiv($minutes, 60)).' * * *' : '*/'.max(5, $minutes).' * * * *';

Schedule::job(new SyncBillbee)
    ->cron($every((int) config('marktschreier.sync.billbee_interval_minutes', 15)))
    ->when(fn () => app(BillbeeClient::class)->isConfigured())
    ->name('billbee-sync')->withoutOverlapping();

Schedule::job(new \App\Jobs\SyncBillbeeOrders)
    ->cron($every((int) config('marktschreier.sync.orders_interval_minutes', 30)))
    ->when(fn () => app(BillbeeClient::class)->isConfigured())
    ->name('billbee-orders')->withoutOverlapping();

Schedule::job(new ImportShopifyProducts)
    ->cron($every((int) config('marktschreier.sync.shopify_interval_minutes', 60)))
    ->when(fn () => app(ShopifyClient::class)->isConfigured() && \App\Models\SyncRun::lastSuccessful(\App\Models\SyncRun::TYPE_SHOPIFY_IMPORT))
    ->name('shopify-import')->withoutOverlapping();
