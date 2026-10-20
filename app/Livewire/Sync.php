<?php

namespace App\Livewire;

use App\Jobs\ImportShopifyProducts;
use App\Jobs\PushProductToShopify;
use App\Jobs\SyncBillbee;
use App\Models\Product;
use App\Models\SyncRun;
use App\Services\Billbee\BillbeeClient;
use App\Services\Shopify\ShopifyClient;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Abgleich')]
class Sync extends Component
{
    public ?string $notice = null;

    public function importShopify(bool $full = false): void
    {
        if ($this->isRunning(SyncRun::TYPE_SHOPIFY_IMPORT)) {
            $this->notice = 'Ein Import läuft bereits.';

            return;
        }
        $run = SyncRun::create(['type' => SyncRun::TYPE_SHOPIFY_IMPORT, 'status' => 'queued', 'stats' => []]);
        ImportShopifyProducts::dispatch($full, $run->id);
        $this->notice = $full ? 'Vollständiger Import gestartet.' : 'Import der Änderungen gestartet.';
    }

    public function syncBillbee(): void
    {
        if ($this->isRunning(SyncRun::TYPE_BILLBEE)) {
            $this->notice = 'Der Billbee-Abgleich läuft bereits.';

            return;
        }
        $run = SyncRun::create(['type' => SyncRun::TYPE_BILLBEE, 'status' => 'queued', 'stats' => []]);
        SyncBillbee::dispatch($run->id);
        $this->notice = 'Billbee-Abgleich gestartet.';
    }

    public function syncOrders(): void
    {
        if ($this->isRunning(SyncRun::TYPE_BILLBEE_ORDERS)) {
            $this->notice = 'Das Einlesen der Verkäufe läuft bereits.';

            return;
        }
        $run = SyncRun::create(['type' => SyncRun::TYPE_BILLBEE_ORDERS, 'status' => 'queued', 'stats' => []]);
        \App\Jobs\SyncBillbeeOrders::dispatch($run->id);
        $this->notice = SyncRun::lastSuccessful(SyncRun::TYPE_BILLBEE_ORDERS)
            ? 'Neue und geänderte Bestellungen werden geholt.'
            : 'Die Bestellungen der letzten 12 Monate werden geholt. Das kann einige Minuten dauern.';
    }

    public function marketplace(string $provider, string $action): void
    {
        if (! in_array($provider, \App\Services\Marketplaces\MarketplaceManager::PROVIDERS, true) || ! in_array($action, ['listings', 'prices'], true)) {
            return;
        }
        if ($this->isRunning($action.'_'.$provider)) {
            $this->notice = 'Läuft bereits.';

            return;
        }
        $run = SyncRun::create(['type' => $action.'_'.$provider, 'status' => 'queued', 'stats' => []]);
        \App\Jobs\SyncMarketplace::dispatch($provider, $action, $run->id);
        $this->notice = $action === 'prices' ? 'Offene Preise werden übertragen.' : 'Angebote werden eingelesen.';
    }

    public function pushAll(): void
    {
        $ids = Product::where('push_status', Product::STATUS_PENDING)->pluck('id');
        foreach ($ids as $id) {
            PushProductToShopify::dispatch($id, auth()->id());
        }
        $this->notice = $ids->count()
            ? $ids->count().' Artikel werden übertragen. Artikel mit Konflikt oder Fehler bitte einzeln prüfen.'
            : 'Keine Artikel mit offenen Änderungen ohne Konflikt.';
    }

    private function isRunning(string $type): bool
    {
        return SyncRun::where('type', $type)->whereIn('status', ['queued', 'running'])
            ->where('created_at', '>', now()->subHours(2))->exists();
    }

    public function render()
    {
        $runs = SyncRun::latest('id')->limit(40)->get();

        return view('livewire.sync', [
            'runs' => $runs,
            'active' => $runs->contains(fn ($r) => in_array($r->status, ['queued', 'running'], true) && $r->created_at > now()->subHours(2)),
            'shopifyReady' => app(ShopifyClient::class)->isConfigured(),
            'billbeeReady' => app(BillbeeClient::class)->isConfigured(),
            'lastImport' => SyncRun::lastSuccessful(SyncRun::TYPE_SHOPIFY_IMPORT),
            'lastBillbee' => SyncRun::lastSuccessful(SyncRun::TYPE_BILLBEE),
            'lastOrders' => SyncRun::lastSuccessful(SyncRun::TYPE_BILLBEE_ORDERS),
            'orderCount' => \App\Models\Order::count(),
            'marketplaces' => collect(\App\Services\Marketplaces\MarketplaceManager::PROVIDERS)->mapWithKeys(fn ($p) => [$p => [
                'name' => \App\Services\Marketplaces\MarketplaceManager::NAMES[$p],
                'connection' => \App\Models\MarketplaceConnection::for($p),
                'pending' => \App\Models\MarketplaceConnection::for($p)->isConnected() ? app(\App\Services\Marketplaces\MarketplaceManager::class)->pendingPrices($p)->count() : 0,
            ]]),
            'counts' => Product::selectRaw('push_status, count(*) as n')->groupBy('push_status')->pluck('n', 'push_status'),
        ]);
    }
}
