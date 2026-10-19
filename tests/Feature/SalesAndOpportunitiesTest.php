<?php

namespace Tests\Feature;

use App\Livewire\Opportunities;
use App\Models\Channel;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Billbee\OrderSync;
use App\Services\Opportunities\OpportunityFinder;
use App\Services\Sales\SalesStats;
use App\Services\Shopify\ProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\FakesApis;
use Tests\TestCase;

class SalesAndOpportunitiesTest extends TestCase
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
        $this->product->variants[0]->update(['cost_net' => 3.00, 'stock' => 100]);
        $this->product->variants[1]->update(['cost_net' => 3.00, 'stock' => 2]);
        $this->actingAs(User::factory()->create(['name' => 'Benny']));
    }

    private function bbOrder(int $id, string $platform, array $items, string $date = '-3 days', int $state = 3): array
    {
        return [
            'BillBeeOrderId' => $id, 'OrderNumber' => 'A'.$id, 'State' => $state,
            'CreatedAt' => now()->modify($date)->toIso8601String(), 'LastModifiedAt' => now()->toIso8601String(),
            'TotalCost' => array_sum(array_column($items, 'TotalPrice')), 'ShippingCost' => 0, 'Currency' => 'EUR',
            'Seller' => ['Platform' => $platform, 'BillbeeShopName' => $platform.'-Shop'],
            'Buyer' => ['Name' => 'Darf nicht gespeichert werden'],
            'InvoiceAddress' => ['FirstName' => 'Max', 'Street' => 'Geheimweg 1'],
            'OrderItems' => $items,
        ];
    }

    private function item(string $sku, int $qty, float $gross, ?string $ean = null): array
    {
        return ['Product' => ['SKU' => $sku, 'EAN' => $ean, 'Title' => 'x'], 'Quantity' => $qty, 'TotalPrice' => $gross, 'TaxAmount' => round($gross - $gross / 1.19, 2), 'IsCoupon' => false];
    }

    private function page(array $orders): array
    {
        return ['Paging' => ['Page' => 1, 'TotalPages' => 1], 'ErrorMessage' => null, 'Data' => $orders];
    }

    public function test_order_import_maps_channels_and_variants_without_customer_data(): void
    {
        $this->queueBillbee($this->page([
            $this->bbOrder(1, 'Shopify', [$this->item('JM-SLV-BLK', 2, 19.80)]),
            $this->bbOrder(2, 'Amazon', [$this->item('IRGENDWAS', 1, 9.90, '4260000000028')]),
            $this->bbOrder(3, 'Kaufland', [$this->item('UNBEKANNT', 1, 5)]),
            $this->bbOrder(4, 'Amazon', [$this->item('JM-SLV-BLK', 5, 49.50)], '-2 days', 8),
        ]));

        $stats = app(OrderSync::class)->run();

        $this->assertSame(4, $stats['orders']);
        $this->assertSame(1, $stats['items_unmatched']);
        $this->assertSame(['Kaufland' => 1], $stats['unknown_platforms']);
        $this->assertSame('shopify', Order::where('billbee_id', 1)->first()->channel->key);
        $this->assertSame($this->product->variants[1]->id, OrderItem::where('ean', '4260000000028')->value('variant_id'));
        $this->assertSame('3.0000', OrderItem::where('sku', 'JM-SLV-BLK')->first()->cost_net);

        // Keine Kundendaten in der Datenbank
        $dump = json_encode([Order::all()->toArray(), OrderItem::all()->toArray()]);
        $this->assertStringNotContainsString('Geheimweg', $dump);
        $this->assertStringNotContainsString('Darf nicht', $dump);

        // Erster Lauf holt 365 Tage, der nächste nur Geänderte
        Http::assertSent(fn ($r) => str_contains($r->url(), '/orders') && isset($r->data()['minOrderDate']) || str_contains($r->url(), 'minOrderDate'));
        $this->queueBillbee($this->page([]));
        app(OrderSync::class)->run();
        $this->assertTrue(Http::recorded(fn ($r) => str_contains($r->url(), 'modifiedAtMin'))->isNotEmpty());
    }

    public function test_sales_stats_profit_excludes_cancelled_and_uses_channel_costs(): void
    {
        $this->queueBillbee($this->page([
            $this->bbOrder(1, 'Shopify', [$this->item('JM-SLV-BLK', 2, 23.80)]),
            $this->bbOrder(2, 'Amazon', [$this->item('JM-SLV-BLK', 5, 49.50)], '-2 days', 8),
        ]));
        app(OrderSync::class)->run();

        $s = app(SalesStats::class)->period(30);
        $this->assertSame(1, $s['total']['orders']);
        // netto 20,00 - EK 6,00 - 2,5 % von 23,80 (0,595) - 0,25 fest = 13,155
        $this->assertEqualsWithDelta(13.155, $s['total']['profit'], 0.01);
        $this->assertEqualsWithDelta(2, $s['variants'][$this->product->variants[0]->id]['quantity'], 0.001);
    }

    public function test_opportunities_cover_free_and_ads(): void
    {
        $orders = [];
        for ($i = 1; $i <= 12; $i++) {
            $orders[] = $this->bbOrder($i, 'Shopify', [$this->item('JM-SLV-BLK', 1, 14.90), $this->item('JM-SLV-RED', 1, 9.90)], '-'.($i * 2).' days');
        }
        $this->queueBillbee($this->page($orders));
        app(OrderSync::class)->run();
        // Rote Variante: Verlust auf Shopify durch hohen EK
        $this->product->variants[1]->update(['cost_net' => 8.50]);

        $types = app(OpportunityFinder::class)->all()->groupBy('type')->map->count();

        $this->assertGreaterThanOrEqual(1, $types['missing_channel'] ?? 0);   // Amazon, eBay ohne Verkauf
        $this->assertSame(1, $types['stockout'] ?? 0);                        // rot: 2 Stück, ~3,5/Woche
        $this->assertSame(1, $types['ad_candidate'] ?? 0);
        $this->assertSame(1, $types['incomplete'] ?? 0);                      // kurze Beschreibung usw.

        $ad = app(OpportunityFinder::class)->all()->firstWhere('type', 'ad_candidate');
        $this->assertArrayHasKey('Werbekosten-Grenze', $ad->facts);
    }

    public function test_low_profit_is_found(): void
    {
        $this->product->variants[1]->update(['cost_net' => 8.50]);
        $this->queueBillbee($this->page([$this->bbOrder(1, 'Shopify', [$this->item('JM-SLV-RED', 1, 9.90)])]));
        app(OrderSync::class)->run();

        $o = app(OpportunityFinder::class)->all()->firstWhere('type', 'low_profit');
        $this->assertNotNull($o);
        $this->assertStringContainsString('Verlust', $o->title);
    }

    public function test_dismissing_hides_and_restores(): void
    {
        $this->queueBillbee($this->page([$this->bbOrder(1, 'Shopify', [$this->item('JM-SLV-BLK', 1, 14.90)])]));
        app(OrderSync::class)->run();
        $first = app(OpportunityFinder::class)->all()->first();
        $key = $first->key();

        Livewire::test(Opportunities::class)->call('ignore', $key)->assertDontSee($first->title);
        $this->assertFalse(app(OpportunityFinder::class)->all()->contains(fn ($o) => $o->key() === $key));

        Livewire::test(Opportunities::class)->set('showDismissed', true)->assertSee('Wieder zeigen')->call('restore', $key);
        $this->assertTrue((new OpportunityFinder(new SalesStats, new \App\Services\Pricing\PriceCalculator))->all()->contains(fn ($o) => $o->key() === $key));
    }

    public function test_rematch_after_platform_names_change(): void
    {
        $this->queueBillbee($this->page([$this->bbOrder(1, 'Kaufland.de', [$this->item('JM-SLV-BLK', 1, 9.90)])]));
        app(OrderSync::class)->run();
        $this->assertNull(Order::sole()->channel_id);

        $tiktok = Channel::where('key', 'tiktok')->first();
        Livewire::test(\App\Livewire\Pricing::class)->set("channels.{$tiktok->id}.billbee_platforms", 'tiktok, kaufland')->call('save');

        $this->assertSame($tiktok->id, Order::sole()->channel_id);
    }

    public function test_dashboard_and_pages_render(): void
    {
        $this->get('/')->assertOk()->assertSee('Noch keine Verkäufe');
        $this->queueBillbee($this->page([$this->bbOrder(1, 'Amazon', [$this->item('JM-SLV-BLK', 3, 29.70)])]));
        app(OrderSync::class)->run();
        $this->app->forgetScopedInstances(); // neue Anfrage, frische Auswertung

        $this->get('/')->assertOk()->assertSee('Umsatz brutto')->assertSee('Amazon')->assertSee('Benny');
        $this->get('/?days=7')->assertOk();
        $this->get('/chancen')->assertOk();
        $this->get('/chancen?kind=ads')->assertOk();
        $this->get(route('products.edit', $this->product))->assertOk()->assertSee('Verkauft (30 Tage)');
        $this->assertSame('ok', SyncRun::where('type', SyncRun::TYPE_BILLBEE_ORDERS)->first()->status);
    }
}
