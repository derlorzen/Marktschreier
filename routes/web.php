<?php

use App\Livewire\Login;
use App\Livewire\Products\Edit;
use App\Livewire\Products\Index;
use App\Livewire\Sync;
use App\Models\Product;
use App\Support\OpenTabs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::livewire('/login', Login::class)->middleware('guest')->name('login');

Route::post('/logout', function (Request $request) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::livewire('/', \App\Livewire\Dashboard::class)->name('dashboard');
    Route::livewire('/artikel', Index::class)->name('products.index');
    Route::livewire('/artikel/neu', \App\Livewire\Products\Create::class)->name('products.create');
    Route::livewire('/artikel/{product}', Edit::class)->name('products.edit');
    Route::get('/artikel/{product}/schliessen', function (Product $product) {
        $next = OpenTabs::close($product->id);
        // Wer den aktiven Reiter schließt, landet beim Nachbarn, sonst bleibt er, wo er war.
        $back = url()->previous();
        if ($back === route('products.edit', $product) || str_starts_with($back, route('products.edit', $product).'?')) {
            return $next ? redirect()->route('products.edit', $next) : redirect()->route('products.index');
        }

        return redirect($back ?: route('products.index'));
    })->name('products.close');
    Route::get('/artikel-reiter/schliessen', function () {
        OpenTabs::closeAll();

        return redirect()->route('products.index');
    })->name('products.close-all');
    Route::livewire('/hinweise', \App\Livewire\Alerts::class)->name('alerts');
    Route::livewire('/chancen', \App\Livewire\Opportunities::class)->name('opportunities');
    Route::livewire('/kalender', \App\Livewire\Calendar::class)->name('calendar');
    Route::livewire('/werbung', \App\Livewire\Ads::class)->name('ads');
    Route::livewire('/preiswaechter', \App\Livewire\PriceWatches::class)->name('price-watches');
    Route::livewire('/bestand', \App\Livewire\Stock::class)->name('stock');
    Route::livewire('/preise', \App\Livewire\Pricing::class)->name('pricing');
    Route::livewire('/abgleich', Sync::class)->name('sync');
    Route::livewire('/einstellungen/konto', \App\Livewire\Settings\Account::class)->name('settings.account');

    Route::middleware('can:admin')->prefix('einstellungen')->group(function () {
        Route::livewire('/', \App\Livewire\Settings\Overview::class)->name('settings');
        Route::livewire('/allgemein', \App\Livewire\Settings\General::class)->name('settings.general');
        Route::livewire('/benutzer', \App\Livewire\Settings\Users::class)->name('settings.users');
        Route::livewire('/sicherung', \App\Livewire\Settings\Backups::class)->name('settings.backups');
        Route::get('/sicherung/{name}', function (string $name, \App\Services\BackupService $backups) {
            $path = $backups->path($name);
            abort_unless($path, 404);

            return response()->download($path);
        })->name('settings.backups.download');
        Route::livewire('/zeitplan', \App\Livewire\Settings\Schedule::class)->name('settings.schedule');
        Route::livewire('/shopify', \App\Livewire\Settings\Shopify::class)->name('settings.shopify');
        Route::livewire('/billbee', \App\Livewire\Settings\Billbee::class)->name('settings.billbee');
        Route::livewire('/ebay', \App\Livewire\Settings\Ebay::class)->name('settings.ebay');
        Route::livewire('/amazon', \App\Livewire\Settings\Amazon::class)->name('settings.amazon');
        Route::livewire('/amazon-werbung', \App\Livewire\Settings\AmazonAds::class)->name('settings.ads');
        Route::get('/amazon-werbung/verbinden', [\App\Http\Controllers\MarketplaceAuthController::class, 'adsConnect'])->name('settings.ads.connect');
        Route::get('/amazon-werbung/rueckkehr', [\App\Http\Controllers\MarketplaceAuthController::class, 'adsCallback'])->name('settings.ads.callback');
        Route::get('/ebay/verbinden', [\App\Http\Controllers\MarketplaceAuthController::class, 'ebayConnect'])->name('settings.ebay.connect');
        Route::get('/ebay/rueckkehr', [\App\Http\Controllers\MarketplaceAuthController::class, 'ebayCallback'])->name('settings.ebay.callback');
        Route::get('/amazon/verbinden', [\App\Http\Controllers\MarketplaceAuthController::class, 'amazonConnect'])->name('settings.amazon.connect');
        Route::get('/amazon/rueckkehr', [\App\Http\Controllers\MarketplaceAuthController::class, 'amazonCallback'])->name('settings.amazon.callback');
    });
});
