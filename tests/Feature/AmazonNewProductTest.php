<?php

namespace Tests\Feature;

use App\Livewire\Products\AmazonNewProduct;
use App\Models\Listing;
use App\Models\MarketplaceConnection;
use App\Models\Product;
use App\Models\User;
use App\Services\Marketplaces\Amazon\AmazonProductBuilder;
use App\Services\Shopify\ProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\FakesApis;
use Tests\TestCase;

class AmazonNewProductTest extends TestCase
{
    use FakesApis, RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['marktschreier.amazon.client_id' => 'c', 'marktschreier.amazon.client_secret' => 's']);
        $this->bindApiClients();
        $this->queueShopify($this->productsPage([$this->shopifyNode()]));
        app(ProductImporter::class)->run();
        $this->product = Product::with('variants')->sole();
        $this->product->variants[0]->update(['stock' => 7]);
        $c = MarketplaceConnection::for('amazon');
        $c->storeSecrets(['refresh_token' => 'rt', 'access_token' => 'at', 'access_expires_at' => time() + 3600, 'seller_id' => 'A2SELLER']);
        $c->update(['status' => 'connected']);
        $this->actingAs(User::factory()->create());

        $schema = [
            'required' => ['item_name', 'brand', 'externally_assigned_product_identifier', 'item_type_keyword', 'special_size'],
            'properties' => [
                'item_name' => ['title' => 'Artikelname', 'maxItems' => 1, 'items' => ['required' => ['value'], 'properties' => ['value' => ['type' => 'string', 'maxLength' => 200], 'language_tag' => ['type' => 'string'], 'marketplace_id' => ['type' => 'string']]]],
                'brand' => ['title' => 'Marke', 'items' => ['properties' => ['value' => ['type' => 'string'], 'language_tag' => ['type' => 'string'], 'marketplace_id' => ['type' => 'string']]]],
                'externally_assigned_product_identifier' => ['title' => 'Produkt-ID', 'items' => ['properties' => ['type' => ['type' => 'string', 'enum' => ['ean', 'upc'], 'enumNames' => ['EAN', 'UPC']], 'value' => ['type' => 'string'], 'marketplace_id' => ['type' => 'string']]]],
                'item_type_keyword' => ['title' => 'Artikeltyp', 'items' => ['properties' => ['value' => ['type' => 'string', 'enum' => ['card-sleeves', 'deck-boxes'], 'enumNames' => ['Kartenhüllen', 'Deckboxen']], 'marketplace_id' => ['type' => 'string']]]],
                'special_size' => ['title' => 'Maße', 'items' => ['properties' => ['dimensions' => ['type' => 'object'], 'marketplace_id' => ['type' => 'string']]]],
            ],
        ];
        Http::fake([
            'sellingpartnerapi-eu.amazon.com/definitions/2020-09-01/productTypes?*' => Http::response(['productTypes' => [['name' => 'CARD_SLEEVE', 'displayName' => 'Kartenhülle', 'marketplaceIds' => ['A1PA6795UKMFR9']]], 'productTypeVersion' => ['version' => 'x']]),
            'sellingpartnerapi-eu.amazon.com/definitions/2020-09-01/productTypes/CARD_SLEEVE*' => Http::response(['schema' => ['link' => ['resource' => 'https://schemas.example.amazon/card.json', 'verb' => 'GET'], 'checksum' => 'abc']]),
            'schemas.example.amazon/*' => Http::response($schema),
            'sellingpartnerapi-eu.amazon.com/listings/*' => Http::sequence()
                ->push(['sku' => 'JM-SLV-BLK', 'status' => 'INVALID', 'issues' => [['severity' => 'ERROR', 'message' => 'Pflichtangabe fehlt', 'attributeNames' => ['item_type_keyword']]]])
                ->push(['sku' => 'JM-SLV-BLK', 'status' => 'VALID', 'issues' => []])
                ->push(['sku' => 'JM-SLV-BLK', 'status' => 'ACCEPTED', 'submissionId' => 'sub1', 'issues' => []]),
        ]);
    }

    public function test_fields_are_derived_from_schema(): void
    {
        $fields = collect(app(AmazonProductBuilder::class)->requiredFields('CARD_SLEEVE'))->keyBy('name');
        $this->assertSame('simple', $fields['item_name']['kind']);
        $this->assertTrue($fields['item_name']['language']);
        $this->assertSame('group', $fields['externally_assigned_product_identifier']['kind']);
        $this->assertSame(['card-sleeves' => 'Kartenhüllen', 'deck-boxes' => 'Deckboxen'], $fields['item_type_keyword']['parts'][0]['enum']);
        $this->assertSame('complex', $fields['special_size']['kind']);
    }

    public function test_full_flow_with_validation_preview(): void
    {
        $v = $this->product->variants[0];
        $c = Livewire::test(AmazonNewProduct::class, ['variantId' => $v->id])
            ->call('searchTypes')->assertSee('Kartenhülle')
            ->call('pickType', 'CARD_SLEEVE')
            ->assertSet('values.item_name', 'Sleeves Matt 100 Stück')
            ->assertSet('values.brand', 'Johto Market')
            ->assertSet('values.externally_assigned_product_identifier', ['type' => 'ean', 'value' => '4260000000011']);

        $c->call('validateWithAmazon')->assertSee('Pflichtangabe fehlt')
            ->call('create')->assertSet('error', fn ($e) => str_contains($e, 'zuerst prüfen'));
        $c->set('values.item_type_keyword', 'card-sleeves')->call('validateWithAmazon')->assertSee('keine Fehler')
            ->call('create')->assertSet('done', fn ($d) => str_contains($d, 'übergeben'));

        $this->assertSame('pending', Listing::sole()->status);
        $this->assertTrue(Listing::sole()->data['new_asin']);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), 'mode=VALIDATION_PREVIEW')
            && $r['requirements'] === 'LISTING' && $r['productType'] === 'CARD_SLEEVE');
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && ! str_contains($r->url(), 'VALIDATION_PREVIEW')
            && $r['attributes']['item_type_keyword'][0] === ['value' => 'card-sleeves', 'marketplace_id' => 'A1PA6795UKMFR9']
            && $r['attributes']['item_name'][0]['language_tag'] === 'de_DE'
            && $r['attributes']['externally_assigned_product_identifier'][0] === ['type' => 'ean', 'value' => '4260000000011', 'marketplace_id' => 'A1PA6795UKMFR9']
            && $r['attributes']['fulfillment_availability'][0]['quantity'] === 7
            && ! isset($r['attributes']['special_size']));
    }

    public function test_complex_field_requires_valid_json(): void
    {
        $v = $this->product->variants[0];
        Livewire::test(AmazonNewProduct::class, ['variantId' => $v->id])
            ->call('pickType', 'CARD_SLEEVE')->set('values.special_size', '{kaputt')
            ->call('validateWithAmazon')->assertSet('error', fn ($e) => str_contains($e, 'gültiges JSON'));
    }
}
