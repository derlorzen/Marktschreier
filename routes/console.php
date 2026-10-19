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

Artisan::command('marktschreier:user {email} {name?} {--password=} {--staff : Als Mitarbeiter statt Administrator}', function (string $email, ?string $name = null) {
    $password = $this->option('password') ?: $this->secret('Passwort');
    if (! $password || strlen($password) < 10) {
        $this->error('Das Passwort muss mindestens 10 Zeichen haben.');

        return 1;
    }
    $user = User::updateOrCreate(['email' => $email], [
        'name' => $name ?: $email, 'password' => Hash::make($password), 'active' => true,
        'role' => $this->option('staff') ? User::STAFF : User::ADMIN,
    ]);
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

Artisan::command('marktschreier:marketplace {provider : ebay oder amazon} {action=listings : listings oder prices}', function (string $provider, string $action) {
    $m = app(\App\Services\Marketplaces\MarketplaceManager::class);
    $stats = $action === 'prices' ? $m->pushPrices($provider) : $m->syncListings($provider);
    $this->table(['Wert', 'Anzahl'], collect($stats)->map(fn ($v, $k) => [$k, $v])->values());
})->purpose('Marktplatz: Angebote lesen oder offene Preise übertragen');

Artisan::command('marktschreier:billbee', function () {
    $stats = app(StockAndCostSync::class)->run();
    $this->table(['Wert', 'Anzahl'], collect($stats)->map(fn ($v, $k) => [$k, is_array($v) ? count($v) : $v])->values());
})->purpose('EK und Bestand aus Billbee lesen');

$every = fn (int $minutes) => $minutes >= 1440 ? '17 3 * * *' : ($minutes >= 60 ? '0 */'.max(1, intdiv($minutes, 60)).' * * *' : '*/'.max(5, $minutes).' * * * *');
$plan = \App\Livewire\Settings\Schedule::class;

Schedule::job(new SyncBillbee)
    ->cron($every($plan::interval('billbee')))
    ->when(fn () => $plan::enabled('billbee') && app(BillbeeClient::class)->isConfigured())
    ->name('billbee-sync')->withoutOverlapping();

Schedule::job(new \App\Jobs\SyncBillbeeOrders)
    ->cron($every($plan::interval('orders')))
    ->when(fn () => $plan::enabled('orders') && app(BillbeeClient::class)->isConfigured())
    ->name('billbee-orders')->withoutOverlapping();

Schedule::job(new ImportShopifyProducts)
    ->cron($every($plan::interval('shopify')))
    ->when(fn () => $plan::enabled('shopify') && app(ShopifyClient::class)->isConfigured() && \App\Models\SyncRun::lastSuccessful(\App\Models\SyncRun::TYPE_SHOPIFY_IMPORT))
    ->name('shopify-import')->withoutOverlapping();

foreach (\App\Services\Marketplaces\MarketplaceManager::PROVIDERS as $provider) {
    Schedule::job(new \App\Jobs\SyncMarketplace($provider, 'listings'))
        ->cron(str_replace('0 ', '20 ', $every(max(60, $plan::interval('listings')))))
        ->when(fn () => $plan::enabled('listings') && rescue(fn () => \App\Models\MarketplaceConnection::for($provider)->isConnected(), false, false))
        ->name('listings-'.$provider)->withoutOverlapping();
}

Schedule::job(new \App\Jobs\RunAlertChecks)->hourlyAt(40)->name('alert-checks')->withoutOverlapping();

Schedule::call(function () {
    $review = app(\App\Services\Alerts\WeeklyReview::class)->build();
    foreach (\App\Models\User::where('active', true)->where('mail_weekly', true)->get() as $user) {
        \Illuminate\Support\Facades\Mail::to($user->email)->queue(new \App\Mail\WeeklyReviewMail($review));
    }
})->weeklyOn(1, '7:45')->timezone('Europe/Berlin')->name('weekly-review');

Artisan::command('marktschreier:alerts', function () {
    app(\App\Services\Alerts\AlertChecks::class)->run();
    $this->info(\App\Models\Alert::open()->count().' offene Hinweise');
})->purpose('Hinweise prüfen und aktualisieren');

// Lokal (SQLite) sichert Marktschreier selbst, mit Postgres übernimmt das der Dienst "backup".
Schedule::call(fn () => app(\App\Services\BackupService::class)->now())
    ->dailyAt('2:30')->timezone('Europe/Berlin')
    ->when(fn () => \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'sqlite')
    ->name('backup-sqlite');

Schedule::call(function () {
    $list = app(\App\Services\BackupService::class)->list();
    if (! $list || $list[0]['time'] < now()->subHours(36)->timestamp) {
        app(\App\Services\Alerts\AlertService::class)->raise('backup:stale', 'backup', 'bad', 'Keine aktuelle Sicherung', 'Seit mehr als 36 Stunden wurde die Datenbank nicht gesichert.', '/einstellungen/sicherung');
    } else {
        app(\App\Services\Alerts\AlertService::class)->resolve('backup:stale');
    }
})->dailyAt('9:05')->timezone('Europe/Berlin')->name('backup-check');

Schedule::job(new \App\Jobs\RunPriceWatches)->everyThirtyMinutes()
    ->when(fn () => rescue(fn () => \App\Models\MarketplaceConnection::for('amazon')->isConnected() && \App\Models\PriceWatch::where('active', true)->exists(), false, false))
    ->name('price-watches')->withoutOverlapping();

Schedule::job(new \App\Jobs\SyncAds)->dailyAt('6:10')->timezone('Europe/Berlin')
    ->when(fn () => rescue(fn () => \App\Models\MarketplaceConnection::for('amazon_ads')->isConnected() && \App\Models\MarketplaceConnection::for('amazon_ads')->setting('profile_id'), false, false))
    ->name('ads-sync')->withoutOverlapping();
