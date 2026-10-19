<?php

namespace Tests\Feature;

use App\Models\ChangeLog;
use App\Models\Product;
use App\Models\SyncRun;
use App\Services\Shopify\ProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesApis;
use Tests\TestCase;

class ShopifyImportTest extends TestCase
{
    use FakesApis, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindApiClients();
    }

    private function import(array ...$pages): array
    {
        $this->queueShopify(...$pages);

        return app(ProductImporter::class)->run();
    }

    public function test_first_import_creates_products_variants_and_images(): void
    {
        $stats = $this->import($this->productsPage([$this->shopifyNode()]));

        $this->assertSame(1, $stats['products_new']);
        $product = Product::with('variants', 'images')->sole();
        $this->assertSame('Sleeves Matt 100 Stück', $product->title);
        $this->assertSame(['TCG', 'Sleeves'], $product->tags);
        $this->assertSame(Product::STATUS_SYNCED, $product->push_status);
        $this->assertCount(2, $product->variants);
        $this->assertSame('4260000000011', $product->variants[0]->barcode);
        $this->assertSame('Schwarz', $product->variants[0]->option1);
        $this->assertSame('9.90', $product->variants[0]->price);
        $this->assertCount(1, $product->images);
        $this->assertFalse($product->hasPendingChanges());
        $this->assertSame('ok', SyncRun::sole()->status);

        Http::assertSent(fn ($r) => $r->hasHeader('X-Shopify-Access-Token', 'shpat_test')
            && str_contains($r->url(), '/admin/api/2026-10/graphql.json'));
    }

    public function test_follows_pagination(): void
    {
        $this->import(
            $this->productsPage([$this->shopifyNode()], true, 'CUR1'),
            $this->productsPage([$this->shopifyNode(['id' => 'gid://shopify/Product/2', 'handle' => 'b'], [$this->shopifyVariant(['id' => 'gid://shopify/ProductVariant/21', 'sku' => 'B'])])]),
        );

        $this->assertSame(2, Product::count());
        Http::assertSent(fn ($r) => ($r['variables']['after'] ?? null) === 'CUR1');
    }

    public function test_remote_change_updates_untouched_fields_and_is_logged(): void
    {
        $this->import($this->productsPage([$this->shopifyNode()]));
        $this->import($this->productsPage([$this->shopifyNode(['title' => 'Sleeves Matt 100 Stück NEU'])]));

        $product = Product::sole();
        $this->assertSame('Sleeves Matt 100 Stück NEU', $product->title);
        $this->assertSame(Product::STATUS_SYNCED, $product->push_status);
        $this->assertTrue(ChangeLog::where('field', 'title')->where('source', 'import')->exists());
    }

    public function test_local_edit_survives_import_when_shopify_did_not_change_the_field(): void
    {
        $this->import($this->productsPage([$this->shopifyNode()]));
        $product = Product::sole();
        $product->update(['title' => 'Mein besserer Titel']);

        $this->import($this->productsPage([$this->shopifyNode(['vendor' => 'Neue Marke'])]));

        $product->refresh();
        $this->assertSame('Mein besserer Titel', $product->title);
        $this->assertSame('Neue Marke', $product->vendor);
        $this->assertSame(Product::STATUS_PENDING, $product->push_status);
        $this->assertSame(['title'], array_keys($product->pendingChanges()));
    }

    public function test_same_field_changed_on_both_sides_is_a_conflict_and_keeps_local_value(): void
    {
        $this->import($this->productsPage([$this->shopifyNode()]));
        $variant = Product::sole()->variants()->first();
        $variant->update(['price' => '11.90']);

        $node = $this->shopifyNode();
        $node['variants']['nodes'][0]['price'] = '10.90';
        $stats = $this->import($this->productsPage([$node]));

        $this->assertSame(1, $stats['conflicts']);
        $this->assertSame('11.90', $variant->fresh()->price);
        $this->assertSame(Product::STATUS_CONFLICT, Product::sole()->push_status);
        // Basis ist jetzt der neue Shopify-Stand, übertragen würde 10.90 -> 11.90.
        $this->assertSame(['old' => '10.90', 'new' => '11.90'], $variant->fresh()->pendingChanges()['price']);
    }

    public function test_variant_deleted_in_shopify_is_removed(): void
    {
        $this->import($this->productsPage([$this->shopifyNode()]));
        $node = $this->shopifyNode();
        array_pop($node['variants']['nodes']);
        $this->import($this->productsPage([$node]));

        $this->assertSame(['JM-SLV-BLK'], Product::sole()->variants->pluck('sku')->all());
    }

    public function test_delta_import_sends_updated_at_filter(): void
    {
        $this->queueShopify($this->productsPage([]));
        app(ProductImporter::class)->run(now()->setDate(2026, 10, 18)->setTime(8, 0));

        Http::assertSent(fn ($r) => str_starts_with((string) ($r['variables']['query'] ?? ''), "updated_at:>='2026-10-18T"));
    }

    public function test_failed_import_is_recorded(): void
    {
        $this->queueShopify(Http::response('nope', 401));

        try {
            app(ProductImporter::class)->run();
            $this->fail('Exception erwartet');
        } catch (\App\Services\Shopify\ShopifyException $e) {
            $this->assertStringContainsString('lehnt den Zugriff ab', $e->getMessage());
        }
        $this->assertSame('failed', SyncRun::sole()->status);
    }
}
