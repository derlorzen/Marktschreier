<?php

namespace Tests\Feature;

use App\Livewire\Products\Marketplaces;
use App\Models\Channel;
use App\Models\ChannelPrice;
use App\Models\Listing;
use App\Models\MarketplaceConnection;
use App\Models\Product;
use App\Models\User;
use App\Services\Marketplaces\Amazon\AmazonService;
use App\Services\Marketplaces\Ebay\EbayService;
use App\Services\Marketplaces\MarketplaceManager;
use App\Services\Shopify\ProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\FakesApis;
use Tests\TestCase;

class MarketplaceTest extends TestCase
{
    use FakesApis, RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'marktschreier.ebay.client_id' => 'ebay-cid', 'marktschreier.ebay.client_secret' => 'ebay-secret', 'marktschreier.ebay.runame' => 'Marktschreier-RuName',
            'marktschreier.amazon.client_id' => 'amzn-cid', 'marktschreier.amazon.client_secret' => 'amzn-secret', 'marktschreier.amazon.app_id' => 'amzn1.sp.solution.test',
        ]);
        $this->bindApiClients();
        $this->queueShopify($this->productsPage([$this->shopifyNode()]));
        app(ProductImporter::class)->run();
        $this->product = Product::with('variants')->sole();
        $this->product->variants[0]->update(['stock' => 12]);
        $this->product->variants[1]->update(['stock' => 3]);
        $this->actingAs(User::factory()->create());
    }

    private function connect(string $provider, array $extra = []): void
    {
        $c = MarketplaceConnection::for($provider);
        $c->storeSecrets(array_replace(['refresh_token' => 'rt', 'access_token' => 'at', 'access_expires_at' => time() + 3600], $extra));
        $c->update(['status' => 'connected', 'connected_at' => now()]);
    }

    private function ebayXml(string $call, string $body, string $ack = 'Success'): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><'.$call.'Response xmlns="urn:ebay:apis:eBLBaseComponents"><Ack>'.$ack.'</Ack>'.$body.'</'.$call.'Response>';
    }

    public function test_ebay_oauth_flow_stores_tokens_and_checks_state(): void
    {
        Http::fake([
            'api.ebay.com/identity/*' => Http::response(['access_token' => 'user-at', 'expires_in' => 7200, 'refresh_token' => 'user-rt', 'refresh_token_expires_in' => 47304000]),
            'api.ebay.com/ws/api.dll' => Http::response($this->ebayXml('GetUser', '<User><UserID>johtomarket</UserID></User>')),
            '*' => Http::response([], 404),
        ]);
        \Illuminate\Support\Facades\Queue::fake();

        $redirect = $this->get(route('settings.ebay.connect'))->assertRedirect();
        $this->assertStringContainsString('auth.ebay.com/oauth2/authorize', $redirect->headers->get('Location'));
        $state = session('oauth_state.ebay');

        $this->get(route('settings.ebay.callback', ['code' => 'abc', 'state' => 'falsch']))->assertRedirect(route('settings.ebay'));
        $this->assertFalse(MarketplaceConnection::for('ebay')->isConnected());

        session(['oauth_state.ebay' => $state]);
        $this->get(route('settings.ebay.callback', ['code' => 'abc', 'state' => $state]))->assertRedirect(route('settings.ebay'));
        $c = MarketplaceConnection::for('ebay')->fresh();
        $this->assertTrue($c->isConnected());
        $this->assertSame('johtomarket', $c->account_name);
        $this->assertSame('user-rt', $c->secrets()['refresh_token']);
        $this->assertStringNotContainsString('user-rt', (string) $c->getRawOriginal('credentials'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2/token') && $r['grant_type'] === 'authorization_code' && $r['redirect_uri'] === 'Marktschreier-RuName');
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncMarketplace::class);
    }

    public function test_ebay_refreshes_expired_token(): void
    {
        $this->connect('ebay', ['access_expires_at' => time() - 10]);
        Http::fake([
            'api.ebay.com/identity/*' => Http::response(['access_token' => 'fresh', 'expires_in' => 7200]),
            'api.ebay.com/ws/api.dll' => Http::response($this->ebayXml('GetUser', '<User><UserID>x</UserID></User>')),
        ]);
        app(EbayService::class)->refreshAccountName();
        Http::assertSent(fn (Request $r) => $r->hasHeader('X-EBAY-API-IAF-TOKEN', 'fresh') && $r->hasHeader('X-EBAY-API-SITEID', '77'));
    }

    public function test_ebay_listing_sync_matches_skus_and_adopts_remote_price(): void
    {
        $this->connect('ebay');
        $xml = $this->ebayXml('GetMyeBaySelling', '<ActiveList><ItemArray>'
            .'<Item><ItemID>111</ItemID><Title>Sleeves</Title><Variations>'
            .'<Variation><SKU>JM-SLV-BLK</SKU><StartPrice currencyID="EUR">10.49</StartPrice></Variation>'
            .'<Variation><SKU>JM-SLV-RED</SKU><StartPrice currencyID="EUR">9.90</StartPrice></Variation>'
            .'</Variations></Item>'
            .'<Item><ItemID>222</ItemID><SKU>FREMD</SKU><SellingStatus><CurrentPrice currencyID="EUR">5.00</CurrentPrice></SellingStatus></Item>'
            .'</ItemArray><PaginationResult><TotalNumberOfPages>1</TotalNumberOfPages></PaginationResult></ActiveList>');
        Http::fake(['api.ebay.com/ws/api.dll' => Http::response($xml)]);

        $stats = app(MarketplaceManager::class)->syncListings('ebay');

        $this->assertSame(['listings' => 2, 'matched' => 2, 'unmatched' => 1], $stats);
        $ebay = Channel::where('key', 'ebay')->first();
        $black = $this->product->variants[0];
        // Abweichender eBay-Preis wird als Kanalpreis übernommen, nichts ist offen.
        $this->assertSame('10.49', ChannelPrice::where('variant_id', $black->id)->where('channel_id', $ebay->id)->value('price'));
        $this->assertCount(0, app(MarketplaceManager::class)->pendingPrices('ebay'));
        $this->assertTrue(Listing::where('external_id', '111')->first()->data['variation']);
    }

    public function test_ebay_price_push_sends_only_price(): void
    {
        $this->connect('ebay');
        $ebay = Channel::where('key', 'ebay')->first();
        $v = $this->product->variants[0];
        Listing::create(['variant_id' => $v->id, 'channel_id' => $ebay->id, 'external_id' => '111', 'sku' => 'JM-SLV-BLK', 'status' => 'active', 'remote_price' => 9.90, 'pushed_price' => 9.90, 'data' => ['variation' => true]]);
        ChannelPrice::create(['variant_id' => $v->id, 'channel_id' => $ebay->id, 'price' => 11.49]);
        Http::fake(['api.ebay.com/ws/api.dll' => Http::response($this->ebayXml('ReviseInventoryStatus', ''))]);

        $this->assertCount(1, app(MarketplaceManager::class)->pendingPrices('ebay'));
        $r = app(MarketplaceManager::class)->pushPrices('ebay');

        $this->assertSame(['ok' => 1, 'failed' => 0], $r);
        Http::assertSent(fn (Request $req) => $req->hasHeader('X-EBAY-API-CALL-NAME', 'ReviseInventoryStatus')
            && str_contains($req->body(), '<StartPrice>11.49</StartPrice>') && str_contains($req->body(), '<SKU>JM-SLV-BLK</SKU>')
            && ! str_contains($req->body(), '<Quantity>'));
        $this->assertCount(0, app(MarketplaceManager::class)->pendingPrices('ebay'));
    }

    public function test_ebay_errors_are_kept_on_listing(): void
    {
        $this->connect('ebay');
        $ebay = Channel::where('key', 'ebay')->first();
        $v = $this->product->variants[0];
        $l = Listing::create(['variant_id' => $v->id, 'channel_id' => $ebay->id, 'external_id' => '111', 'sku' => 'JM-SLV-BLK', 'status' => 'active', 'pushed_price' => 5]);
        Http::fake(['api.ebay.com/ws/api.dll' => Http::response($this->ebayXml('ReviseInventoryStatus', '<Errors><SeverityCode>Error</SeverityCode><LongMessage>Angebot ist beendet.</LongMessage></Errors>', 'Failure'))]);

        $this->assertSame(['ok' => 0, 'failed' => 1], app(EbayService::class)->pushPrices(collect([$l->load('variant', 'channel')])));
        $this->assertSame('eBay: Angebot ist beendet.', $l->fresh()->last_error);
    }

    public function test_ebay_create_listing_with_variations(): void
    {
        $this->connect('ebay');
        MarketplaceConnection::for('ebay')->putSettings(['fulfillment_policy' => 'F1', 'payment_policy' => 'P1', 'return_policy' => 'R1', 'postal_code' => '24392', 'location' => 'Süderbrarup']);
        Http::fake(['api.ebay.com/ws/api.dll' => Http::sequence()
            ->push($this->ebayXml('VerifyAddFixedPriceItem', '<Fees><Fee><Name>InsertionFee</Name><Fee currencyID="EUR">0.0</Fee></Fee></Fees>'))
            ->push($this->ebayXml('AddFixedPriceItem', '<ItemID>333</ItemID>'))]);

        $svc = app(EbayService::class);
        $check = $svc->createListing($this->product, '183454', ['Marke' => 'Johto Market'], verifyOnly: true);
        $this->assertNull($check['item_id']);
        $this->assertSame(0.0, $check['fees']);
        $this->assertSame(0, Listing::count());

        $r = $svc->createListing($this->product, '183454', ['Marke' => 'Johto Market']);
        $this->assertSame('333', $r['item_id']);
        $this->assertSame(2, Listing::where('external_id', '333')->where('created_by_us', true)->count());

        Http::assertSent(function (Request $req) {
            $b = $req->body();

            return $req->hasHeader('X-EBAY-API-CALL-NAME', 'AddFixedPriceItem')
                && str_contains($b, '<Variations>') && str_contains($b, '<SKU>JM-SLV-RED</SKU>') && str_contains($b, '<Quantity>3</Quantity>')
                && str_contains($b, '<ShippingProfileID>F1</ShippingProfileID>') && str_contains($b, '<Name>Farbe</Name>')
                && str_contains($b, '<EAN>4260000000028</EAN>') && str_contains($b, '<PictureURL>');
        });
    }

    public function test_ebay_listing_requires_settings_and_stock(): void
    {
        $this->connect('ebay');
        $this->expectExceptionMessage('Rücknahmerichtlinie');
        app(EbayService::class)->createListing($this->product, '1', []);
    }

    public function test_amazon_manual_connect_and_listing_sync(): void
    {
        Http::fake([
            'api.amazon.com/auth/o2/token' => Http::response(['access_token' => 'Atza|x', 'expires_in' => 3600]),
            'sellingpartnerapi-eu.amazon.com/sellers/*' => Http::response(['payload' => [['marketplace' => ['id' => 'A1PA6795UKMFR9', 'name' => 'Amazon.de'], 'storeName' => 'Johto Market', 'participation' => ['isParticipating' => true]]]]),
            'sellingpartnerapi-eu.amazon.com/listings/*' => Http::response(['items' => [
                ['sku' => 'JM-SLV-BLK', 'summaries' => [['marketplaceId' => 'A1PA6795UKMFR9', 'asin' => 'B0TEST0001', 'status' => ['BUYABLE', 'DISCOVERABLE'], 'itemName' => 'Sleeves']], 'offers' => [['offerType' => 'B2C', 'price' => ['currencyCode' => 'EUR', 'amount' => '11.99']]], 'issues' => []],
                ['sku' => 'JM-SLV-RED', 'summaries' => [['marketplaceId' => 'A1PA6795UKMFR9', 'asin' => 'B0TEST0002', 'status' => ['DISCOVERABLE']]], 'offers' => [], 'issues' => [['severity' => 'ERROR', 'message' => 'Preis fehlt']]],
            ]]),
        ]);

        Livewire::test(\App\Livewire\Settings\Amazon::class)
            ->set('manualSellerId', 'A2EXAMPLE1234')->set('manualRefreshToken', 'Atzr|abc')
            ->call('connectManually')->assertHasNoErrors()->assertSet('notice', fn ($n) => str_contains($n, 'Johto Market'));
        $this->assertTrue(MarketplaceConnection::for('amazon')->isConnected());

        $stats = app(MarketplaceManager::class)->syncListings('amazon');
        $this->assertSame(['checked' => 2, 'found' => 2, 'errors' => 1, 'fba' => 0], $stats);
        $black = Listing::where('sku', 'JM-SLV-BLK')->sole();
        $this->assertSame('active', $black->status);
        $this->assertSame('B0TEST0001', $black->external_id);
        $this->assertSame('error', Listing::where('sku', 'JM-SLV-RED')->value('status'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/listings/2021-08-01/items/A2EXAMPLE1234?') && str_contains(urldecode($r->url()), 'identifiers=JM-SLV-BLK,JM-SLV-RED')
            && $r->hasHeader('x-amz-access-token', 'Atza|x'));
    }

    public function test_amazon_offer_creation_and_price_patch(): void
    {
        $this->connect('amazon', ['seller_id' => 'A2EXAMPLE1234']);
        Http::fake([
            'sellingpartnerapi-eu.amazon.com/catalog/*' => Http::response(['items' => [['asin' => 'B0CAT00001', 'summaries' => [['itemName' => 'Sleeves Matt', 'brand' => 'Johto']], 'images' => []]]]),
            'sellingpartnerapi-eu.amazon.com/listings/*' => Http::response(['sku' => 'JM-SLV-BLK', 'status' => 'ACCEPTED', 'submissionId' => 's1', 'issues' => []]),
        ]);
        $v = $this->product->variants[0];

        Livewire::test(Marketplaces::class, ['product' => $this->product])
            ->call('amazonLookup', $v->id)->assertSee('B0CAT00001')
            ->call('amazonCreate', $v->id)->assertSet('error', null);

        $l = Listing::sole();
        $this->assertSame('pending', $l->status);
        $this->assertSame('9.90', $l->pushed_price);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['requirements'] === 'LISTING_OFFER_ONLY'
            && $r['attributes']['merchant_suggested_asin'][0]['value'] === 'B0CAT00001'
            && $r['attributes']['fulfillment_availability'][0]['quantity'] === 12
            && $r['attributes']['purchasable_offer'][0]['our_price'][0]['schedule'][0]['value_with_tax'] === 9.9);

        $amazon = Channel::where('key', 'amazon')->first();
        app(\App\Services\ProductEditor::class)->setChannelPrice($v, $amazon, '12.49');
        $this->assertSame(['ok' => 1, 'failed' => 0], app(MarketplaceManager::class)->pushPrices('amazon'));
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['patches'][0]['path'] === '/attributes/purchasable_offer'
            && $r['patches'][0]['value'][0]['our_price'][0]['schedule'][0]['value_with_tax'] === 12.49);
    }

    public function test_marketplace_tab_and_sync_page_render(): void
    {
        $this->connect('ebay');
        $this->get(route('products.edit', $this->product).'?tab=marktplaetze')->assertOk()->assertSee('eBay')->assertSee('Amazon');
        $this->get('/abgleich')->assertOk()->assertSee('Angebote lesen');
        $this->get('/einstellungen')->assertOk()->assertSee('eBay');
    }
}
