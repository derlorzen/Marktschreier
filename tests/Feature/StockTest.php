<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Channel;
use App\Models\Listing;
use App\Models\MarketplaceConnection;
use App\Models\Product;
use App\Models\User;
use App\Services\Alerts\AlertChecks;
use App\Services\Billbee\StockAndCostSync;
use App\Services\Marketplaces\Amazon\AmazonService;
use App\Services\Shopify\ProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesApis;
use Tests\TestCase;

class StockTest extends TestCase
{
    use FakesApis, RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindApiClients();
        $this->queueShopify($this->productsPage([$this->shopifyNode()]));
        app(ProductImporter::class)->run();
        $this->product = Product::with('variants')->sole();
        $this->actingAs(User::factory()->create());
    }

    public function test_billbee_stock_locations_are_stored(): void
    {
        $this->queueBillbee(['Paging' => ['Page' => 1, 'TotalPages' => 1], 'Data' => [[
            'Id' => 1, 'SKU' => 'JM-SLV-BLK', 'StockCurrent' => 7, 'CostPrice' => 3,
            'Stocks' => [['Name' => 'Hauptlager', 'StockId' => 1, 'StockCurrent' => 5, 'UnfulfilledAmount' => 1], ['Name' => 'Messe', 'StockId' => 2, 'StockCurrent' => 2]],
        ]]]);
        app(StockAndCostSync::class)->run();
        $v = $this->product->variants[0]->fresh();
        $this->assertSame([['name' => 'Hauptlager', 'quantity' => 5, 'reserved' => 1], ['name' => 'Messe', 'quantity' => 2, 'reserved' => 0]], $v->stock_locations);
    }

    public function test_oversell_warning_and_alert(): void
    {
        $ebay = Channel::where('key', 'ebay')->first();
        $v = $this->product->variants[0];
        $v->update(['stock' => 2]);
        Listing::create(['variant_id' => $v->id, 'channel_id' => $ebay->id, 'external_id' => '1', 'sku' => $v->sku, 'status' => 'active', 'remote_quantity' => 5, 'fulfillment' => 'FBM']);

        app(AlertChecks::class)->run();
        $this->assertSame(1, Alert::open()->where('type', 'oversell')->count());
        $this->get('/bestand')->assertOk()->assertSee('eBay zeigt 5, im Lager sind 2');

        Listing::sole()->update(['remote_quantity' => 2]);
        app(AlertChecks::class)->run();
        $this->assertSame(0, Alert::open()->where('type', 'oversell')->count());
    }

    public function test_fba_inventory_is_read(): void
    {
        $c = MarketplaceConnection::for('amazon');
        $c->storeSecrets(['refresh_token' => 'rt', 'access_token' => 'at', 'access_expires_at' => time() + 3600, 'seller_id' => 'A2X']);
        $c->update(['status' => 'connected']);
        $amazon = Channel::where('key', 'amazon')->first();
        $v = $this->product->variants[0];
        Listing::create(['variant_id' => $v->id, 'channel_id' => $amazon->id, 'external_id' => 'B0X', 'sku' => 'JM-SLV-BLK', 'status' => 'active', 'fulfillment' => 'FBA']);
        Http::fake(['sellingpartnerapi-eu.amazon.com/fba/*' => Http::response(['payload' => ['inventorySummaries' => [[
            'sellerSku' => 'JM-SLV-BLK', 'totalQuantity' => 40,
            'inventoryDetails' => ['fulfillableQuantity' => 30, 'inboundShippedQuantity' => 8, 'reservedQuantity' => ['totalReservedQuantity' => 2], 'unfulfillableQuantity' => ['totalUnfulfillableQuantity' => 0]],
        ]]]])]);

        $this->assertSame(1, app(AmazonService::class)->syncFbaInventory());
        $l = Listing::sole();
        $this->assertSame(30, $l->fba['fulfillable']);
        $this->assertSame(8, $l->fba['inbound']);
        $this->get('/bestand?filter=fba')->assertOk()->assertSee('JM-SLV-BLK');
    }
}
