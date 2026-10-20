<?php

namespace App\Livewire\Settings;

use App\Models\MarketplaceConnection;
use App\Models\Setting;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Billbee\BillbeeClient;
use App\Services\Shopify\ShopifyClient;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Einstellungen')]
class Overview extends Component
{
    public function render()
    {
        $fmt = fn ($d) => $d?->timezone('Europe/Berlin')->format('d.m.Y H:i');
        $last = fn (string $t) => SyncRun::lastSuccessful($t);
        $failed = fn (string $t) => SyncRun::where('type', $t)->latest('id')->first()?->status === 'failed';
        $ebay = MarketplaceConnection::for(MarketplaceConnection::EBAY);
        $amazon = MarketplaceConnection::for(MarketplaceConnection::AMAZON);

        $tiles = [
            ['route' => 'settings.shopify', 'title' => 'Shopify',
                'state' => ! app(ShopifyClient::class)->isConfigured() ? 'off' : ($failed(SyncRun::TYPE_SHOPIFY_IMPORT) ? 'bad' : 'ok'),
                'text' => app(ShopifyClient::class)->isConfigured()
                    ? (Setting::get('shopify.shop').' · Import '.($fmt($last(SyncRun::TYPE_SHOPIFY_IMPORT)?->finished_at) ?? 'noch keiner'))
                    : 'Noch nicht eingerichtet'],
            ['route' => 'settings.billbee', 'title' => 'Billbee',
                'state' => ! app(BillbeeClient::class)->isConfigured() ? 'off' : ($failed(SyncRun::TYPE_BILLBEE) || $failed(SyncRun::TYPE_BILLBEE_ORDERS) ? 'bad' : 'ok'),
                'text' => app(BillbeeClient::class)->isConfigured()
                    ? 'Abgleich '.($fmt($last(SyncRun::TYPE_BILLBEE)?->finished_at) ?? 'noch keiner')
                    : 'Noch nicht eingerichtet'],
            ['route' => 'settings.ebay', 'title' => 'eBay',
                'state' => $ebay->status === 'error' ? 'bad' : ($ebay->isConnected() ? (blank($ebay->setting('fulfillment_policy')) ? 'warn' : 'ok') : 'off'),
                'text' => $ebay->isConnected() ? ($ebay->account_name ?? 'verbunden').(blank($ebay->setting('fulfillment_policy')) ? ' · Richtlinien fehlen' : '') : ($ebay->status === 'error' ? 'Verbindung gestört' : 'Nicht verbunden')],
            ['route' => 'settings.amazon', 'title' => 'Amazon',
                'state' => $amazon->status === 'error' ? 'bad' : ($amazon->isConnected() ? 'ok' : 'off'),
                'text' => $amazon->isConnected() ? ($amazon->account_name ?? 'verbunden') : ($amazon->status === 'error' ? 'Verbindung gestört' : 'Nicht verbunden')],
            (function () {
                $t = MarketplaceConnection::for(MarketplaceConnection::TIKTOK);

                return ['route' => 'settings.tiktok', 'title' => 'TikTok Shop',
                    'state' => $t->status === 'error' ? 'bad' : ($t->isConnected() ? 'ok' : 'off'),
                    'text' => $t->isConnected() ? ($t->account_name ?? 'verbunden') : ($t->status === 'error' ? 'Verbindung gestört' : 'Nicht verbunden')];
            })(),
            (function () use ($fmt, $last) {
                $ok = (new \App\Services\Plenty\PlentyClient)->isConfigured();
                $run = $last(\App\Services\Plenty\PlentyImporter::TYPE_ITEMS);

                return ['route' => 'settings.plenty', 'title' => 'PlentyONE', 'state' => $ok ? 'ok' : 'off',
                    'text' => $ok ? 'Import '.($fmt($run?->finished_at) ?? 'noch keiner') : 'Nicht angebunden (nur für Umzüge)'];
            })(),
            ['route' => 'settings.users', 'title' => 'Benutzer', 'state' => 'ok',
                'text' => User::where('active', true)->count().' aktiv, davon '.User::where('active', true)->where('role', User::ADMIN)->count().' Administratoren'],
            ['route' => 'settings.schedule', 'title' => 'Abgleich und Zeitplan', 'state' => 'ok',
                'text' => 'Billbee '.Schedule::label(Schedule::interval('billbee')).', Shopify '.Schedule::label(Schedule::interval('shopify'))],
            ['route' => 'settings.general', 'title' => 'Allgemein', 'state' => 'ok',
                'text' => (Setting::get('general.company') ?: 'Ohne Firmenname').' · MwSt. '.rtrim(rtrim(number_format((float) Setting::get('billbee.vat_rate', 19), 2, ',', ''), '0'), ',').' %'],
        ];

        return view('livewire.settings.overview', ['tiles' => $tiles]);
    }
}
