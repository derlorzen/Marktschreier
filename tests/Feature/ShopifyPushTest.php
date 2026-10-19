<?php

namespace Tests\Feature;

use App\Models\ChangeLog;
use App\Models\Product;
use App\Services\Shopify\ProductImporter;
use App\Services\Shopify\ProductPusher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesApis;
use Tests\TestCase;

class ShopifyPushTest extends TestCase
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
    }

    public function test_payload_contains_only_changed_fields(): void
    {
        $this->product->update(['title' => 'Neu', 'tags' => ['Sleeves', 'TCG', 'Matt']]);
        $this->product->variants[1]->update(['price' => '12.5', 'barcode' => '4260000000035']);

        $payload = app(ProductPusher::class)->buildPayload($this->product->fresh());

        $this->assertSame(['id' => 'gid://shopify/Product/1', 'title' => 'Neu', 'tags' => ['Matt', 'Sleeves', 'TCG']], $payload['product']);
        $this->assertSame([[
            'id' => 'gid://shopify/ProductVariant/12',
            'price' => '12.50',
            'barcodes' => [['value' => '4260000000035']],
        ]], $payload['variants']);
    }

    public function test_reordering_tags_is_not_a_change(): void
    {
        $this->product->update(['tags' => ['Sleeves', 'TCG']]);

        $this->assertFalse($this->product->fresh()->hasPendingChanges());
    }

    public function test_successful_push_marks_synced_and_logs(): void
    {
        $this->product->update(['seo_title' => 'Matte Sleeves kaufen']);
        $this->product->variants[0]->update(['sku' => 'JM-SLV-BLK-100']);

        $this->queueShopify(
            ['data' => ['productUpdate' => ['product' => ['id' => 'x'], 'userErrors' => []]]],
            ['data' => ['productVariantsBulkUpdate' => ['productVariants' => [], 'userErrors' => []]]],
        );

        $ok = app(ProductPusher::class)->push($this->product->fresh());

        $this->assertTrue($ok);
        $p = $this->product->fresh();
        $this->assertSame(Product::STATUS_SYNCED, $p->push_status);
        $this->assertFalse($p->hasPendingChanges());
        $this->assertSame(2, ChangeLog::where('source', 'push')->count());

        Http::assertSent(fn ($r) => str_contains($r['query'], 'productUpdate')
            && $r['variables']['product'] === ['id' => 'gid://shopify/Product/1', 'seo' => ['title' => 'Matte Sleeves kaufen', 'description' => '']]);
        Http::assertSent(fn ($r) => str_contains($r['query'], 'productVariantsBulkUpdate')
            && $r['variables']['variants'] === [['id' => 'gid://shopify/ProductVariant/11', 'inventoryItem' => ['sku' => 'JM-SLV-BLK-100']]]);
    }

    public function test_user_errors_mark_product_as_error_and_keep_changes(): void
    {
        $this->product->variants[0]->update(['price' => '-1']);
        $this->queueShopify(['data' => ['productVariantsBulkUpdate' => [
            'productVariants' => [], 'userErrors' => [['field' => ['variants', '0', 'price'], 'message' => 'Price must be greater than or equal to 0']],
        ]]]);

        $ok = app(ProductPusher::class)->push($this->product->fresh());

        $this->assertFalse($ok);
        $p = $this->product->fresh();
        $this->assertSame(Product::STATUS_ERROR, $p->push_status);
        $this->assertStringContainsString('Price must be', $p->push_error);
        $this->assertTrue($p->hasPendingChanges());
    }

    public function test_nothing_to_push_sends_nothing(): void
    {
        $before = count(Http::recorded());
        $this->assertTrue(app(ProductPusher::class)->push($this->product->fresh()));
        $this->assertCount($before, Http::recorded());
    }
}
