<?php

use App\Livewire\Login;
use App\Livewire\Products\Edit;
use App\Livewire\Products\Index;
use App\Livewire\Settings;
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
    Route::redirect('/', '/artikel');
    Route::livewire('/artikel', Index::class)->name('products.index');
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
    Route::livewire('/abgleich', Sync::class)->name('sync');
    Route::livewire('/einstellungen', Settings::class)->name('settings');
});
