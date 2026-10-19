<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Setting;
use App\Models\SyncRun;
use App\Services\Billbee\StockAndCostSync;
use App\Services\Shopify\ProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesApis;
use Tests\TestCase;

class BillbeeSyncTest extends TestCase
{
    use FakesApis, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindApiClients();
        $this->queueShopify($this->productsPage([$this->shopifyNode()]));
        app(ProductImporter::class)->run();
    }

    private function bbPage(array $products, int $page = 1, ?int $totalPages = 1): array
    {
        return ['Paging' => $totalPages ? ['Page' => $page, 'TotalPages' => $totalPages, 'TotalRows' => 0, 'PageSize' => 250] : null,
            'ErrorMessage' => null, 'ErrorCode' => 0, 'Data' => $products];
    }

    private function billbeeRequests(): int
    {
        return Http::recorded(fn ($r) => str_contains($r->url(), 'api.billbee.io'))->count();
    }

    public function test_matches_by_sku_and_falls_back_to_ean(): void
    {
        $this->queueBillbee($this->bbPage([
            ['Id' => 501, 'SKU' => 'jm-slv-blk ', 'EAN' => null, 'CostPrice' => 3.2, 'StockCurrent' => 14.0, 'Vat1Rate' => 19],
            ['Id' => 502, 'SKU' => 'ALT-RED', 'EAN' => '4260000000028', 'CostPrice' => 3.4, 'StockCurrent' => 0, 'Vat1Rate' => 19],
            ['Id' => 503, 'SKU' => 'UNBEKANNT', 'EAN' => '999', 'CostPrice' => 1, 'StockCurrent' => 1],
        ]));

        $stats = app(StockAndCostSync::class)->run();

        $this->assertSame(2, $stats['matched']);
        $this->assertSame(1, $stats['by_sku']);
        $this->assertSame(1, $stats['by_ean']);
        $this->assertSame(1, $stats['unmatched']);
        [$black, $red] = Product::sole()->variants;
        $this->assertSame(501, $black->billbee_id);
        $this->assertSame('3.2000', $black->cost_net);
        $this->assertSame(14, $black->stock);
        $this->assertSame(0, $red->stock);
        $this->assertNotNull($red->billbee_synced_at);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.billbee.io')
            && $r->hasHeader('X-Billbee-Api-Key', 'key') && $r->hasHeader('Authorization', 'Basic '.base64_encode('user:pass')));
    }

    public function test_gross_cost_price_is_converted_to_net(): void
    {
        Setting::put('billbee.cost_price_mode', 'gross');
        $this->queueBillbee($this->bbPage([
            ['Id' => 501, 'SKU' => 'JM-SLV-BLK', 'CostPrice' => 11.9, 'StockCurrent' => 3, 'Vat1Rate' => 19],
        ]));

        app(StockAndCostSync::class)->run();

        $this->assertSame('10.0000', Product::sole()->variants[0]->cost_net);
    }

    public function test_billbee_sync_never_marks_products_as_changed(): void
    {
        $this->queueBillbee($this->bbPage([
            ['Id' => 501, 'SKU' => 'JM-SLV-BLK', 'CostPrice' => 3, 'StockCurrent' => 3],
        ]));

        app(StockAndCostSync::class)->run();

        $this->assertFalse(Product::with('variants')->sole()->hasPendingChanges());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'billbee') && $r->method() !== 'GET');
    }

    public function test_reads_all_pages_and_retries_on_429(): void
    {
        $this->queueBillbee(
            $this->bbPage([['Id' => 501, 'SKU' => 'JM-SLV-BLK', 'CostPrice' => 3, 'StockCurrent' => 1]], 1, 2),
            Http::response('Too many', 429, ['Retry-After' => '1']),
            $this->bbPage([['Id' => 502, 'SKU' => 'JM-SLV-RED', 'CostPrice' => 3, 'StockCurrent' => 2]], 2, 2),
        );

        $stats = app(StockAndCostSync::class)->run();

        $this->assertSame(2, $stats['matched']);
        $this->assertSame(3, $this->billbeeRequests());
    }

    public function test_paging_without_metadata_stops_on_short_page(): void
    {
        $this->queueBillbee($this->bbPage([['Id' => 501, 'SKU' => 'JM-SLV-BLK', 'StockCurrent' => 5]], 1, null));

        app(StockAndCostSync::class)->run();

        $this->assertSame(1, $this->billbeeRequests());
        $this->assertNull(Product::sole()->variants[0]->cost_net);
    }

    public function test_duplicate_skus_are_not_guessed(): void
    {
        Product::sole()->variants[1]->update(['sku' => 'JM-SLV-BLK']);
        $this->queueBillbee($this->bbPage([['Id' => 501, 'SKU' => 'JM-SLV-BLK', 'StockCurrent' => 5]]));

        $stats = app(StockAndCostSync::class)->run();

        $this->assertSame(1, $stats['ambiguous']);
        $this->assertNull(Product::sole()->variants[0]->stock);
    }

    public function test_auth_error_fails_the_run(): void
    {
        $this->queueBillbee(Http::response('', 401));

        $this->expectException(\App\Services\Billbee\BillbeeException::class);
        try {
            app(StockAndCostSync::class)->run();
        } finally {
            $this->assertSame('failed', SyncRun::where('type', SyncRun::TYPE_BILLBEE)->sole()->status);
        }
    }
}
