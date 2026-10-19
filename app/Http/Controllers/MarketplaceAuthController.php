<?php

namespace App\Http\Controllers;

use App\Jobs\SyncMarketplace;
use App\Services\Marketplaces\Amazon\AmazonService;
use App\Services\Marketplaces\Ebay\EbayService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** OAuth-Weiterleitungen zu eBay und Amazon und die Rücksprünge danach. */
class MarketplaceAuthController extends Controller
{
    public function ebayConnect(EbayService $ebay)
    {
        abort_unless($ebay->client()->appConfigured(), 404);
        $state = Str::random(40);
        session(['oauth_state.ebay' => $state]);

        return redirect()->away($ebay->client()->authorizeUrl($state));
    }

    public function ebayCallback(Request $request, EbayService $ebay)
    {
        $back = redirect()->route('settings.ebay');
        if ($request->filled('error') || ! $request->filled('code')) {
            return $back->with('flash_error', 'eBay hat die Verbindung nicht bestätigt'.($request->filled('error') ? ': '.$request->string('error') : '.'));
        }
        if (! hash_equals((string) session()->pull('oauth_state.ebay'), (string) $request->query('state'))) {
            return $back->with('flash_error', 'Der Rücksprung von eBay passt nicht zur Anfrage. Bitte erneut verbinden.');
        }
        try {
            $ebay->client()->exchangeCode((string) $request->query('code'));
            $name = rescue(fn () => $ebay->refreshAccountName(), null, false);
        } catch (\Throwable $e) {
            return $back->with('flash_error', $e->getMessage());
        }
        SyncMarketplace::dispatch('ebay', 'listings');

        return $back->with('flash', 'Mit eBay verbunden'.($name ? " als {$name}" : '').'. Die Angebote werden jetzt eingelesen. Als Nächstes die Geschäftsrichtlinien laden.');
    }

    public function amazonConnect(AmazonService $amazon)
    {
        abort_unless($amazon->client()->canAuthorizeViaWebsite(), 404);
        $state = Str::random(40);
        session(['oauth_state.amazon' => $state]);

        return redirect()->away($amazon->client()->authorizeUrl($state, route('settings.amazon.callback')));
    }

    public function amazonCallback(Request $request, AmazonService $amazon)
    {
        $back = redirect()->route('settings.amazon');
        if (! $request->filled('spapi_oauth_code') || ! $request->filled('selling_partner_id')) {
            return $back->with('flash_error', 'Amazon hat die Verbindung nicht bestätigt.');
        }
        if (! hash_equals((string) session()->pull('oauth_state.amazon'), (string) $request->query('state'))) {
            return $back->with('flash_error', 'Der Rücksprung von Amazon passt nicht zur Anfrage. Bitte erneut verbinden.');
        }
        try {
            $amazon->client()->exchangeCode((string) $request->query('spapi_oauth_code'), route('settings.amazon.callback'), (string) $request->query('selling_partner_id'));
            $name = rescue(fn () => $amazon->refreshAccountName(), null, false);
        } catch (\Throwable $e) {
            return $back->with('flash_error', $e->getMessage());
        }
        SyncMarketplace::dispatch('amazon', 'listings');

        return $back->with('flash', 'Mit Amazon verbunden'.($name ? ": {$name}" : '').'. Die Angebote werden jetzt eingelesen.');
    }
}
