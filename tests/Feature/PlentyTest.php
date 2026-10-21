<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Plenty\PlentyClient;
use App\Services\Plenty\PlentyImporter;
use App\Services\ProductEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlentyTest extends TestCase
{
    use RefreshDatabase;

    private function page(array $entries): array
    {
        return ['page' => 1, 'totalsCount' => count($entries), 'isLastPage' => true, 'lastPageNumber' => 1, 'entries' => $entries];
    }

    private function item(string $name = 'Ratschen Gartenschere Premium'): array
    {
        return ['id' => 103, 'manufacturerId' => 1, 'mainVariationId' => 1000,
            'itemImages' => [['id' => 1, 'position' => 1, 'url' => 'https://bilder.gartenpaul.de/b.jpg', 'names' => []], ['id' => 2, 'position' => 0, 'url' => 'https://bilder.gartenpaul.de/a.jpg', 'names' => [['alternate' => 'Schere']]]],
            'texts' => [['lang' => 'de', 'name1' => $name, 'description' => '<p>Schneidet gut</p>', 'metaDescription' => 'Ratschenschere', 'keywords' => 'Schere, Garten, ', 'urlPath' => 'ratschenschere', 'title' => '']]];
    }

    private function variation(float $price = 28.5): array
    {
        return ['id' => 1000, 'itemId' => 103, 'isMain' => true, 'isActive' => true, 'number' => 'GP-RS-01', 'position' => 1, 'purchasePrice' => 9.5,
            'variationSalesPrices' => [['salesPriceId' => 1, 'price' => $price], ['salesPriceId' => 4, 'price' => 99]],
            'variationBarcodes' => [['barcodeId' => 1, 'code' => '4260248730039']],
            'variationAttributeValues' => [],
            'stock' => [['warehouseId' => 1, 'netStock' => 12], ['warehouseId' => 2, 'netStock' => 5]]];
    }

    private array $data = [];

    private bool $faked = false;

    private function fakePlenty(array $item, array $variation, array $orders = []): void
    {
        $this->data = compact('item', 'variation', 'orders');
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        Http::fake([
            'gp.example/rest/login' => Http::response(['access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 86400]),
            'gp.example/rest/items/manufacturers*' => Http::response($this->page([['id' => 1, 'name' => 'Gartenpaul']])),
            'gp.example/rest/items/variations*' => fn () => Http::response($this->page([$this->data['variation']])),
            'gp.example/rest/items?*' => fn () => Http::response($this->page([$this->data['item']])),
            'gp.example/rest/orders/referrers' => Http::response([['id' => 1, 'name' => 'Mandant (Shop)'], ['id' => 4, 'name' => 'Amazon']]),
            'gp.example/rest/orders?*' => fn () => Http::response($this->page($this->data['orders'])),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('plenty.url', 'https://gp.example');
        Setting::put('plenty.username', 'api');
        Setting::put('plenty.password', 'geheim');
        Setting::put('plenty.warehouse_ids', '1');
        $this->app->bind(PlentyClient::class, fn () => new PlentyClient(null, null, null, fn () => null));
    }

    public function test_items_and_variations_are_imported(): void
    {
        $this->fakePlenty($this->item(), $this->variation());
        $stats = app(PlentyImporter::class)->importItems(true);

        $this->assertSame(1, $stats['products_new']);
        $p = Product::with('variants', 'images')->sole();
        $this->assertSame(103, (int) $p->plenty_id);
        $this->assertSame('Ratschen Gartenschere Premium', $p->title);
        $this->assertSame('Gartenpaul', $p->vendor);
        $this->assertEqualsCanonicalizing(['Garten', 'Schere'], $p->tags);
        $this->assertSame('https://bilder.gartenpaul.de/a.jpg', $p->images->first()->url);
        $v = $p->variants->sole();
        $this->assertSame('GP-RS-01', $v->sku);
        $this->assertSame('28.50', $v->price);
        $this->assertSame('9.5000', $v->cost_net);
        $this->assertSame(12, $v->stock); // nur Lager 1
        $this->assertFalse($p->hasPendingChanges());
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer tok') && str_contains($r->url(), 'items?') && str_contains($r->url(), 'with=itemImages'));
    }

    public function test_variation_details_and_asin_are_taken_over_without_overwriting_local_care(): void
    {
        $variation = $this->variation() + ['model' => 'RS-PRO', 'weightG' => 240, 'lengthMM' => 210, 'widthMM' => 60, 'heightMM' => 25,
            'marketIdentNumbers' => [['type' => 'ePID', 'value' => '123'], ['type' => 'ASIN', 'value' => 'b07gpaul01']]];
        $second = ['id' => 1001, 'itemId' => 103, 'isMain' => false, 'isActive' => false, 'number' => 'GP-RS-02', 'position' => 2,
            'variationSalesPrices' => [], 'variationBarcodes' => [], 'variationAttributeValues' => [], 'stock' => []];
        Http::fake([
            'gp.example/rest/login' => Http::response(['access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 86400]),
            'gp.example/rest/items/manufacturers*' => Http::response($this->page([['id' => 1, 'name' => 'Gartenpaul']])),
            // Nebenvariante kommt zuerst, PlentyONE bestimmt trotzdem die Hauptvariante
            'gp.example/rest/items/variations*' => Http::response($this->page([$second, $variation])),
            'gp.example/rest/items?*' => Http::response($this->page([$this->item()])),
        ]);
        app(PlentyImporter::class)->importItems(true);

        $main = \App\Models\Variant::where('plenty_variation_id', 1000)->sole();
        $this->assertSame('B07GPAUL01', $main->asin);
        $this->assertSame('RS-PRO', $main->model);
        $this->assertSame('210 × 60 × 25 mm', $main->dimensionsLabel());
        $this->assertSame(240, $main->weight_g);
        $this->assertTrue($main->is_main);
        $other = \App\Models\Variant::where('plenty_variation_id', 1001)->sole();
        $this->assertFalse($other->is_main);
        $this->assertFalse($other->active);
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'marketIdentNumbers'));

        app(ProductEditor::class)->update($main->product, [], [$main->id => ['model' => 'Eigene Pflege']]);
        app(PlentyImporter::class)->importItems(true);
        $this->assertSame('Eigene Pflege', $main->fresh()->model);
    }

    public function test_local_edits_survive_reimport_and_remote_changes_apply(): void
    {
        $this->fakePlenty($this->item(), $this->variation());
        app(PlentyImporter::class)->importItems(true);
        $p = Product::with('variants')->sole();
        $this->actingAs(User::factory()->create());
        app(ProductEditor::class)->update($p, ['title' => 'Mein Titel']);

        $this->fakePlenty($this->item('Neuer Name aus plenty'), $this->variation(29.99));
        app(PlentyImporter::class)->importItems(true);

        $p->refresh();
        $this->assertSame('Mein Titel', $p->title);
        $this->assertSame('conflict', $p->push_status);
        $this->assertSame('29.99', $p->variants()->sole()->price);
    }

    public function test_orders_are_imported_without_coupons_and_shipping(): void
    {
        $this->fakePlenty($this->item(), $this->variation(), [[
            'id' => 225451, 'referrerId' => 1, 'typeId' => 1, 'statusId' => 5, 'createdAt' => now()->subDay()->toIso8601String(),
            'dates' => [['typeId' => 2, 'date' => now()->subDay()->toIso8601String()]],
            'amounts' => [['isSystemCurrency' => true, 'currency' => 'EUR', 'grossTotal' => 44.3, 'netTotal' => 37.23]],
            'orderItems' => [
                ['typeId' => 1, 'itemVariationId' => 1000, 'quantity' => 2, 'orderItemName' => 'Schere', 'amounts' => [['priceGross' => 28.5, 'priceNet' => 23.9496]]],
                ['typeId' => 6, 'itemVariationId' => 0, 'quantity' => 1, 'orderItemName' => 'shipping costs', 'amounts' => [['priceGross' => 0, 'priceNet' => 0]]],
                ['typeId' => 4, 'itemVariationId' => 0, 'quantity' => 1, 'orderItemName' => 'Gutschein', 'amounts' => [['priceGross' => -12.7, 'priceNet' => -10.67]]],
            ],
        ], ['id' => 225452, 'referrerId' => 4, 'typeId' => 1, 'statusId' => 8.1, 'createdAt' => now()->toIso8601String(), 'amounts' => [], 'orderItems' => []]]);
        app(PlentyImporter::class)->importItems(true);
        $stats = app(PlentyImporter::class)->importOrders();

        $this->assertSame(2, $stats['orders']);
        $o = Order::where('plenty_id', 225451)->sole();
        $this->assertSame(Channel::where('key', 'shopify')->value('id'), $o->channel_id);
        $this->assertSame(1, $o->items()->count());
        $this->assertSame('57.00', $o->items()->sole()->total_gross);
        $this->assertSame(8, Order::where('plenty_id', 225452)->value('state'));
        $this->assertSame(Channel::where('key', 'amazon')->value('id'), Order::where('plenty_id', 225452)->value('channel_id'));
        $this->assertNull($o->billbee_id);
    }

    public function test_settings_page(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/einstellungen/plentyone')->assertOk()->assertSee('PlentyONE');
        $this->get('/einstellungen')->assertOk()->assertSee('PlentyONE');
    }
}
