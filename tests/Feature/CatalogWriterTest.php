<?php

namespace Tests\Feature;

use App\Livewire\Products\Create;
use App\Livewire\Products\Edit;
use App\Livewire\Products\Images;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Shopify\CatalogWriter;
use App\Services\Shopify\ProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\FakesApis;
use Tests\TestCase;

class CatalogWriterTest extends TestCase
{
    use FakesApis, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindApiClients();
        $this->actingAs(User::factory()->create());
    }

    public function test_create_article_locally_then_in_shopify(): void
    {
        Livewire::test(Create::class)
            ->set('title', 'Deck Box Neu')->set('vendor', 'Johto Market')
            ->set('options.0.name', 'Farbe')->set('options.0.values', 'Schwarz, Weiß')
            ->set('defaultPrice', '12,90')
            ->set('rows.Schwarz.sku', 'DB-NEU-S')->set('rows.Weiß.sku', 'DB-NEU-W')->set('rows.Weiß.barcode', '4006381333931')
            ->call('save')->assertHasNoErrors()->assertRedirect();

        $p = Product::with('variants')->sole();
        $this->assertNull($p->shopify_id);
        $this->assertSame(Product::STATUS_NEW, $p->push_status);
        $this->assertSame(['Schwarz', 'Weiß'], $p->variants->pluck('option1')->all());
        $this->assertSame('12.90', $p->variants[0]->price);

        $created = $this->shopifyNode(['id' => 'gid://shopify/Product/77', 'title' => 'Deck Box Neu', 'status' => 'DRAFT', 'tags' => [], 'descriptionHtml' => null, 'media' => ['nodes' => []]], [
            $this->shopifyVariant(['id' => 'gid://shopify/ProductVariant/771', 'sku' => 'DB-NEU-S', 'price' => '12.90', 'selectedOptions' => [['name' => 'Farbe', 'value' => 'Schwarz']]]),
            $this->shopifyVariant(['id' => 'gid://shopify/ProductVariant/772', 'sku' => 'DB-NEU-W', 'price' => '12.90', 'barcode' => '4006381333931', 'position' => 2, 'selectedOptions' => [['name' => 'Farbe', 'value' => 'Weiß']]]),
        ]);
        $this->queueShopify(
            ['data' => ['productSet' => ['product' => ['id' => 'gid://shopify/Product/77', 'variants' => ['nodes' => [['id' => 'gid://shopify/ProductVariant/771', 'sku' => 'DB-NEU-S'], ['id' => 'gid://shopify/ProductVariant/772', 'sku' => 'DB-NEU-W']]]], 'userErrors' => []]]],
            ['data' => ['product' => $created]],
        );

        Livewire::test(Edit::class, ['product' => $p])->call('push')->assertSet('notice', 'In Shopify als Entwurf angelegt.');

        $this->assertSame(1, Product::count());
        $p = Product::with('variants')->sole();
        $this->assertSame('gid://shopify/Product/77', $p->shopify_id);
        $this->assertSame(Product::STATUS_SYNCED, $p->push_status);
        $this->assertSame(['gid://shopify/ProductVariant/771', 'gid://shopify/ProductVariant/772'], $p->variants->pluck('shopify_id')->all());
        Http::assertSent(fn ($r) => str_contains($r['query'] ?? '', 'productSet') && $r['variables']['input']['productOptions'][0]['name'] === 'Farbe'
            && $r['variables']['input']['variants'][1]['barcodes'][0]['value'] === '4006381333931'
            && $r['variables']['input']['variants'][0]['inventoryItem']['sku'] === 'DB-NEU-S');
    }

    public function test_create_article_without_some_combinations(): void
    {
        $c = Livewire::test(Create::class)
            ->set('title', 'Shirt')
            ->set('options.0.name', 'Farbe')->set('options.0.values', 'rot, grün')
            ->call('addOption')
            ->set('options.1.name', 'Größe')->set('options.1.values', 'klein, groß')
            ->set('defaultPrice', '9,90');
        $this->assertSame(['rot / klein', 'rot / groß', 'grün / klein', 'grün / groß'], array_keys($c->get('rows')));

        $c->call('removeRow', 'rot / klein')->call('removeRow', 'rot / groß');
        $this->assertSame(['grün / klein', 'grün / groß'], array_keys($c->get('rows')));
        $this->assertSame(['rot / klein', 'rot / groß'], $c->get('excluded'));

        $c->call('restoreRow', 'rot / groß');
        $this->assertSame(['rot / groß', 'grün / klein', 'grün / groß'], array_keys($c->get('rows')));

        $c->set('rows.rot / groß.sku', 'SH-RG')->set('rows.grün / klein.sku', 'SH-GK')->set('rows.grün / groß.sku', 'SH-GG')
            ->call('save')->assertHasNoErrors()->assertRedirect();

        $p = Product::with('variants')->sole();
        $this->assertSame(['rot / groß', 'grün / klein', 'grün / groß'], $p->variants->pluck('title')->all());
        $this->assertSame([['rot', 'groß'], ['grün', 'klein'], ['grün', 'groß']], $p->variants->map(fn ($v) => [$v->option1, $v->option2])->all());
        $this->assertSame(['rot', 'grün'], $p->options[0]['values']);
        $this->assertSame(['klein', 'groß'], $p->options[1]['values']);
    }

    public function test_restoring_a_variant_keeps_its_entered_values(): void
    {
        $c = Livewire::test(Create::class)
            ->set('title', 'Shirt')
            ->set('options.0.name', 'Farbe')->set('options.0.values', 'rot, grün')
            ->set('defaultPrice', '9,90')
            ->set('rows.rot.sku', 'SH-R')->set('rows.rot.barcode', '4006381333931')->set('rows.rot.price', '12,50');

        $c->call('removeRow', 'rot');
        $this->assertArrayNotHasKey('rot', $c->get('rows'));

        $c->call('restoreRow', 'rot');
        $this->assertSame(['sku' => 'SH-R', 'barcode' => '4006381333931', 'price' => '12,50'], $c->get('rows')['rot']);
        $this->assertSame([], $c->get('removedRows'));

        // Verschwindet die Kombination ganz, werden auch die aufgehobenen Eingaben verworfen
        $c->call('removeRow', 'rot')->set('options.0.values', 'grün, blau');
        $this->assertSame([], $c->get('removedRows'));
        $c->set('options.0.values', 'rot, grün');
        $this->assertSame('', $c->get('rows')['rot']['sku']);
    }

    public function test_option_values_containing_a_slash_are_kept_together(): void
    {
        Livewire::test(Create::class)
            ->set('title', 'Socken')
            ->set('options.0.name', 'Größe')->set('options.0.values', 'S / M, L / XL')
            ->call('addOption')
            ->set('options.1.name', 'Farbe')->set('options.1.values', 'rot')
            ->set('defaultPrice', '4')
            ->set('rows.S / M / rot.sku', 'SO-SM')->set('rows.L / XL / rot.sku', 'SO-LX')
            ->call('save')->assertHasNoErrors();

        $p = Product::with('variants')->sole();
        $this->assertSame([['S / M', 'rot'], ['L / XL', 'rot']], $p->variants->map(fn ($v) => [$v->option1, $v->option2])->all());
        $this->assertSame(['S / M', 'L / XL'], $p->options[0]['values']);
        $this->assertSame(['rot'], $p->options[1]['values']);
    }

    public function test_option_values_without_variant_are_dropped_and_last_variant_stays(): void
    {
        $c = Livewire::test(Create::class)
            ->set('title', 'Becher')
            ->set('options.0.name', 'Farbe')->set('options.0.values', 'rot, blau')
            ->set('defaultPrice', '5');

        $c->call('removeRow', 'rot');
        $c->call('removeRow', 'blau')->assertHasErrors('rows');
        $this->assertSame(['blau'], array_keys($c->get('rows')));

        // Geänderte Werte verwerfen Ausschlüsse, die es nicht mehr gibt
        $c->set('options.0.values', 'gelb, blau');
        $this->assertSame([], $c->get('excluded'));
        $c->call('removeRow', 'gelb');

        $c->set('rows.blau.sku', 'BE-B')->call('save')->assertHasNoErrors();
        $p = Product::with('variants')->sole();
        $this->assertSame(['blau'], $p->variants->pluck('option1')->all());
        $this->assertSame(['blau'], $p->options[0]['values']);
    }

    public function test_metafields_and_add_variant(): void
    {
        $this->queueShopify($this->productsPage([$this->shopifyNode()]));
        app(ProductImporter::class)->run();
        $p = Product::sole();
        $p->attributeValues()->create(['key' => 'Größe (mm)', 'value' => '66 x 91']);
        $this->assertTrue($p->attributesPending());

        $this->queueShopify(['data' => ['metafieldsSet' => ['metafields' => [['key' => 'grosse_mm']], 'userErrors' => []]]]);
        $this->assertSame(1, app(CatalogWriter::class)->pushMetafields($p));
        $this->assertFalse($p->fresh()->attributesPending());
        Http::assertSent(fn ($r) => str_contains($r['query'] ?? '', 'metafieldsSet') && $r['variables']['metafields'][0]['key'] === 'grosse_mm' && $r['variables']['metafields'][0]['namespace'] === 'marktschreier');

        $node = $this->shopifyNode();
        $node['variants']['nodes'][] = $this->shopifyVariant(['id' => 'gid://shopify/ProductVariant/13', 'sku' => 'JM-SLV-GRN', 'position' => 3, 'selectedOptions' => [['name' => 'Farbe', 'value' => 'Grün']]]);
        $this->queueShopify(
            ['data' => ['productVariantsBulkCreate' => ['productVariants' => [['id' => 'gid://shopify/ProductVariant/13', 'sku' => 'JM-SLV-GRN']], 'userErrors' => []]]],
            ['data' => ['product' => $node]],
        );
        Livewire::test(Edit::class, ['product' => $p->fresh()])
            ->set('newVariant.options.0', 'Grün')->set('newVariant.sku', 'JM-SLV-GRN')->set('newVariant.price', '9,90')
            ->call('addVariant')->assertHasNoErrors()->assertSet('notice', 'Variante in Shopify angelegt.');
        $this->assertSame(3, $p->variants()->count());
    }

    public function test_image_upload_reorder_delete(): void
    {
        $this->queueShopify($this->productsPage([$this->shopifyNode(['media' => ['nodes' => [
            ['id' => 'gid://shopify/MediaImage/1', 'alt' => 'a', 'image' => ['url' => 'https://cdn.shopify.com/a.jpg']],
            ['id' => 'gid://shopify/MediaImage/2', 'alt' => 'b', 'image' => ['url' => 'https://cdn.shopify.com/b.jpg']],
        ]]])]));
        app(ProductImporter::class)->run();
        $p = Product::sole();
        Http::fake(['shopify-staged-uploads.storage.googleapis.com/*' => Http::response('', 201)]);

        $node = $this->shopifyNode(['media' => ['nodes' => [
            ['id' => 'gid://shopify/MediaImage/1', 'alt' => 'a', 'image' => ['url' => 'https://cdn.shopify.com/a.jpg']],
            ['id' => 'gid://shopify/MediaImage/2', 'alt' => 'b', 'image' => ['url' => 'https://cdn.shopify.com/b.jpg']],
            ['id' => 'gid://shopify/MediaImage/3', 'alt' => 'neu', 'image' => ['url' => 'https://cdn.shopify.com/c.jpg']],
        ]]]);
        $this->queueShopify(
            ['data' => ['stagedUploadsCreate' => ['stagedTargets' => [['url' => 'https://shopify-staged-uploads.storage.googleapis.com/', 'resourceUrl' => 'https://shopify-staged-uploads.storage.googleapis.com/tmp/c.jpg', 'parameters' => [['name' => 'key', 'value' => 'tmp/c.jpg']]]], 'userErrors' => []]]],
            ['data' => ['productUpdate' => ['product' => ['id' => 'x'], 'userErrors' => []]]],
            ['data' => ['product' => $node]],
            ['data' => ['productReorderMedia' => ['job' => ['id' => 'j'], 'mediaUserErrors' => []]]],
            ['data' => ['productDeleteMedia' => ['deletedMediaIds' => ['gid://shopify/MediaImage/1'], 'mediaUserErrors' => []]]],
        );

        $c = Livewire::test(Images::class, ['product' => $p])
            ->set('uploads', [UploadedFile::fake()->image('c.jpg', 200, 200)])
            ->call('upload')->assertSet('error', null);
        $this->assertSame(3, ProductImage::count());
        Http::assertSent(fn ($r) => str_contains($r->url(), 'storage.googleapis.com') && $r->isMultipart());

        $second = ProductImage::where('shopify_id', 'gid://shopify/MediaImage/2')->first();
        $c->call('move', $second->id, -1)->assertSet('error', null);
        $this->assertSame(1, $second->fresh()->position);

        $first = ProductImage::where('shopify_id', 'gid://shopify/MediaImage/1')->first();
        $c->call('delete', $first->id)->assertSet('error', null);
        $this->assertSame(2, ProductImage::count());
    }
}
