<?php

namespace Tests\Feature;

use App\Livewire\Products\Edit;
use App\Livewire\Products\Index;
use App\Livewire\Products\Marketplaces;
use App\Livewire\Products\VariantDetail;
use App\Models\ChangeLog;
use App\Models\MarketplaceConnection;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\User;
use App\Models\Variant;
use App\Services\Marketplaces\Amazon\AmazonProductBuilder;
use App\Services\Marketplaces\MarketplaceManager;
use App\Services\ProductEditor;
use App\Services\Shopify\ProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\FakesApis;
use Tests\TestCase;

/** Vater-Kind-Aufbau wie bei PlentyONE: Hauptvariante, Varianten-Einstellungen, Merkmale mit Vererbung, Bilder, ASIN. */
class VariantStructureTest extends TestCase
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

    public function test_first_variant_becomes_main_and_main_can_be_switched(): void
    {
        [$black, $red] = $this->product->variants;
        $this->assertTrue($black->is_main);
        $this->assertFalse($red->is_main);
        $this->assertSame($black->id, $this->product->mainVariant()->id);

        Livewire::test(VariantDetail::class, ['product' => $this->product, 'variantId' => $red->id])
            ->call('makeMain')->assertSet('notice', 'Ist jetzt die Hauptvariante.');

        $this->assertSame([$red->id], Variant::where('is_main', true)->pluck('id')->all());
        $this->assertTrue(ChangeLog::where('field', 'is_main')->where('variant_id', $red->id)->exists());
    }

    public function test_variant_settings_are_saved_and_logged_without_marking_shopify_pending(): void
    {
        $v = $this->product->variants[0];
        Livewire::test(VariantDetail::class, ['product' => $this->product, 'variantId' => $v->id])
            ->set('form.asin', 'b0abc12345')->set('form.model', 'JM-100')->set('form.weight_g', '120')
            ->set('form.length_mm', '95')->set('form.width_mm', '68')->set('form.height_mm', '30')
            ->set('form.active', false)
            ->call('save')->assertHasNoErrors()->assertSet('notice', '7 Änderungen gespeichert.')
            ->assertDispatched('variant-saved');

        $v->refresh();
        $this->assertSame('B0ABC12345', $v->asin);
        $this->assertSame('JM-100', $v->model);
        $this->assertSame(120, $v->weight_g);
        $this->assertSame('95 × 68 × 30 mm', $v->dimensionsLabel());
        $this->assertFalse($v->active);
        $this->assertSame('ASIN', Product::fieldLabel(ChangeLog::where('field', 'asin')->value('field')));
        // nur Marktschreier-Felder: nichts für Shopify offen
        $this->assertFalse($this->product->fresh('variants')->hasPendingChanges());
    }

    public function test_variant_settings_validation(): void
    {
        $v = $this->product->variants[0];
        Livewire::test(VariantDetail::class, ['product' => $this->product, 'variantId' => $v->id])
            ->set('form.asin', 'XYZ')->set('form.weight_g', '-5')->set('form.sku', 'JM-SLV-RED')
            ->call('save')->assertHasErrors(['form.asin', 'form.weight_g', 'form.sku']);
        $this->assertNull($v->fresh()->asin);
    }

    public function test_attributes_are_inherited_and_can_be_overridden_per_variant(): void
    {
        ProductAttribute::create(['product_id' => $this->product->id, 'key' => 'Material', 'value' => 'PP-Folie']);
        ProductAttribute::create(['product_id' => $this->product->id, 'key' => 'Anzahl', 'value' => '100']);
        $v = $this->product->variants[1];

        $c = Livewire::test(VariantDetail::class, ['product' => $this->product, 'variantId' => $v->id])
            ->set('section', 'merkmale')
            ->assertSee('PP-Folie')->assertSee('(geerbt)');
        $c->set('attrs.0.value', '60')->call('saveAttributes')->assertSet('notice', '1 Änderung gespeichert.');
        $c->set('newAttr.key', 'Farbton')->set('newAttr.value', 'Rubinrot')->call('addAttribute');

        $v = Variant::with('product.attributeValues', 'attributeValues')->find($v->id);
        $this->assertSame(['Anzahl' => '60', 'Farbton' => 'Rubinrot', 'Material' => 'PP-Folie'], $v->effectiveAttributes());
        $this->assertSame('100', $this->product->attributeValues()->where('key', 'Anzahl')->value('value'), 'Der Vater bleibt unverändert.');

        // Eigenen Wert leeren: es gilt wieder der Wert vom Artikel
        app(ProductEditor::class)->setVariantAttributes($v, ['Anzahl' => '']);
        $v = Variant::with('product.attributeValues', 'attributeValues')->find($v->id);
        $this->assertSame('100', $v->effectiveAttributes()['Anzahl']);
    }

    public function test_images_can_be_assigned_to_a_variant_and_survive_reimport(): void
    {
        $v = $this->product->variants[1];
        $image = $this->product->images()->sole();
        Livewire::test(VariantDetail::class, ['product' => $this->product, 'variantId' => $v->id])
            ->set('section', 'bilder')->set('imageIds', [$image->id])->call('saveImages')
            ->assertSet('notice', '1 Änderung gespeichert.');
        $this->assertSame([$v->id], $image->fresh()->variant_ids);
        $this->assertFalse($image->fresh()->belongsToVariant($this->product->variants[0]->id));

        $this->queueShopify($this->productsPage([$this->shopifyNode(['updatedAt' => '2026-10-19T10:00:00Z'])]));
        app(ProductImporter::class)->run();
        $this->assertSame([$v->id], $this->product->images()->sole()->variant_ids);
    }

    public function test_article_page_opens_variant_detail_and_list_shows_children(): void
    {
        $red = $this->product->variants[1];
        $red->update(['asin' => 'B0RED00001']);

        Livewire::test(Edit::class, ['product' => $this->product])
            ->set('tab', 'varianten')->call('openVariantDetail', $red->id)
            ->assertSet('openVariant', $red->id)->assertSee('Zur Hauptvariante machen')->assertSee('B0RED00001');

        Livewire::test(Index::class)
            ->assertDontSee('B0RED00001')
            ->call('toggleExpand', $this->product->id)->assertSee('ASIN B0RED00001')->assertSee('Haupt');

        Livewire::test(Index::class)->set('search', 'B0RED00001')->assertDontSee('Keine Artikel gefunden');
        Livewire::test(Index::class)->set('search', 'B0NICHTDA1')->assertSee('Keine Artikel gefunden');
    }

    public function test_amazon_sync_fills_missing_asin_but_keeps_a_maintained_one(): void
    {
        [$black, $red] = $this->product->variants;
        $red->update(['asin' => 'B0OWN00002']);
        $this->connectAmazon();
        Http::fake(['sellingpartnerapi-eu.amazon.com/listings/*' => Http::response(['items' => [
            ['sku' => 'JM-SLV-BLK', 'summaries' => [['marketplaceId' => 'A1PA6795UKMFR9', 'asin' => 'B0TEST0001', 'status' => ['BUYABLE']]], 'offers' => [], 'issues' => []],
            ['sku' => 'JM-SLV-RED', 'summaries' => [['marketplaceId' => 'A1PA6795UKMFR9', 'asin' => 'B0TEST0002', 'status' => ['BUYABLE']]], 'offers' => [], 'issues' => []],
        ]]), 'sellingpartnerapi-eu.amazon.com/fba/*' => Http::response(['payload' => ['inventorySummaries' => []]])]);

        app(MarketplaceManager::class)->syncListings('amazon');

        $this->assertSame('B0TEST0001', $black->fresh()->asin);
        $this->assertSame('B0OWN00002', $red->fresh()->asin);
        $this->assertSame('amazon', ChangeLog::where('field', 'asin')->value('source'));
    }

    public function test_stored_asin_is_offered_without_gtin_and_inactive_variants_get_no_offer(): void
    {
        $v = $this->product->variants[0];
        $v->update(['barcode' => null, 'asin' => 'B0STORED01']);
        $this->connectAmazon();
        Http::fake(['sellingpartnerapi-eu.amazon.com/listings/*' => Http::response(['sku' => 'JM-SLV-BLK', 'status' => 'ACCEPTED', 'issues' => []])]);

        Livewire::test(Marketplaces::class, ['product' => $this->product])
            ->call('amazonLookup', $v->id)->assertSee('An der Variante hinterlegte ASIN')
            ->assertSet("amazonChoice.{$v->id}", 'B0STORED01')
            ->call('amazonCreate', $v->id)->assertSet('error', null);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['attributes']['merchant_suggested_asin'][0]['value'] === 'B0STORED01');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/catalog/'));

        $v->update(['active' => false]);
        Livewire::test(Marketplaces::class, ['product' => $this->product])
            ->call('amazonLookup', $v->id)->assertSet('error', 'Die Variante ist inaktiv. Erst in den Varianten-Einstellungen aktivieren.');
    }

    public function test_amazon_prefill_uses_model_and_variant_attributes(): void
    {
        $v = $this->product->variants[1];
        ProductAttribute::create(['product_id' => $this->product->id, 'key' => 'Material', 'value' => 'PP-Folie']);
        $v->update(['model' => 'JM-RED-100']);
        $v->attributeValues()->create(['key' => 'Material', 'value' => 'Polypropylen']);
        $v = Variant::with('product.attributeValues', 'attributeValues')->find($v->id);

        $values = app(AmazonProductBuilder::class)->prefill($v, [
            ['name' => 'model_number', 'title' => 'Modellnummer', 'kind' => 'text'],
            ['name' => 'material', 'title' => 'Material', 'kind' => 'text'],
        ]);
        $this->assertSame(['model_number' => 'JM-RED-100', 'material' => 'Polypropylen'], $values);
    }

    private function connectAmazon(): void
    {
        config(['marktschreier.amazon.client_id' => 'amzn-cid', 'marktschreier.amazon.client_secret' => 'amzn-secret']);
        $c = MarketplaceConnection::for('amazon');
        $c->storeSecrets(['refresh_token' => 'rt', 'access_token' => 'at', 'access_expires_at' => time() + 3600, 'seller_id' => 'A2EXAMPLE1234']);
        $c->update(['status' => 'connected', 'connected_at' => now()]);
    }
}
