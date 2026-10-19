<?php

namespace Tests\Feature;

use App\Livewire\PriceWatches;
use App\Models\Channel;
use App\Models\ChannelPrice;
use App\Models\Listing;
use App\Models\MarketplaceConnection;
use App\Models\PriceWatch;
use App\Models\Product;
use App\Models\User;
use App\Services\Pricing\BuyBoxWatcher;
use App\Services\Shopify\ProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\FakesApis;
use Tests\TestCase;

class BuyBoxTest extends TestCase
{
    use FakesApis, RefreshDatabase;

    private Product $product;

    private Channel $amazon;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindApiClients();
        $this->queueShopify($this->productsPage([$this->shopifyNode()]));
        app(ProductImporter::class)->run();
        $this->product = Product::with('variants')->sole();
        $this->product->variants[0]->update(['cost_net' => 3.00]);
        $this->amazon = Channel::where('key', 'amazon')->first();
        $c = MarketplaceConnection::for('amazon');
        $c->storeSecrets(['refresh_token' => 'rt', 'access_token' => 'at', 'access_expires_at' => time() + 3600, 'seller_id' => 'A2ME']);
        $c->update(['status' => 'connected']);
        Listing::create(['variant_id' => $this->product->variants[0]->id, 'channel_id' => $this->amazon->id, 'external_id' => 'B0SLEEVE01', 'sku' => 'JM-SLV-BLK', 'status' => 'active', 'pushed_price' => 9.90]);
        $this->actingAs(User::factory()->create());
    }

    private function offers(bool $won, float $competitor, ?float $buyBox = null): array
    {
        return ['responses' => [[
            'status' => ['statusCode' => 200], 'request' => ['Asin' => 'B0SLEEVE01'],
            'body' => ['payload' => [
                'ASIN' => 'B0SLEEVE01', 'status' => 'Success',
                'Summary' => ['TotalOfferCount' => 2, 'BuyBoxPrices' => [['condition' => 'New', 'LandedPrice' => ['Amount' => $buyBox ?? $competitor, 'CurrencyCode' => 'EUR'], 'ListingPrice' => ['Amount' => $buyBox ?? $competitor], 'Shipping' => ['Amount' => 0]]]],
                'Offers' => [
                    ['MyOffer' => true, 'IsBuyBoxWinner' => $won, 'ListingPrice' => ['Amount' => 9.90], 'Shipping' => ['Amount' => 0], 'IsFulfilledByAmazon' => false],
                    ['MyOffer' => false, 'IsBuyBoxWinner' => ! $won, 'SellerId' => 'AOTHER', 'ListingPrice' => ['Amount' => $competitor], 'Shipping' => ['Amount' => 0], 'IsFulfilledByAmazon' => true],
                ],
            ]],
        ]]];
    }

    private function watch(array $o = []): PriceWatch
    {
        return PriceWatch::create(array_replace(['variant_id' => $this->product->variants[0]->id, 'strategy' => 'beat', 'min_price' => 8.00, 'max_price' => 12.00, 'auto' => false], $o));
    }

    public function test_suggests_price_one_cent_below_competitor(): void
    {
        $w = $this->watch();
        Http::fake(['sellingpartnerapi-eu.amazon.com/batches/*' => Http::response($this->offers(false, 9.49))]);

        $stats = app(BuyBoxWatcher::class)->run();

        $this->assertSame(1, $stats['suggested']);
        $this->assertSame('9.48', $w->fresh()->suggested_price);
        $this->assertFalse($w->fresh()->last_result['won']);
        Http::assertSent(fn (Request $r) => $r['requests'][0]['uri'] === '/products/pricing/v0/items/B0SLEEVE01/offers' && $r['requests'][0]['ItemCondition'] === 'New');
        $this->assertSame(0, ChannelPrice::count()); // nichts geändert ohne "automatisch"
    }

    public function test_never_below_minimum(): void
    {
        $w = $this->watch(['min_price' => 9.50]);
        Http::fake(['sellingpartnerapi-eu.amazon.com/batches/*' => Http::response($this->offers(false, 7.99))]);
        app(BuyBoxWatcher::class)->run();
        $this->assertSame('9.50', $w->fresh()->suggested_price);
        $this->assertStringContainsString('Mindestpreis', $w->fresh()->last_result['note']);
    }

    public function test_raises_price_when_buybox_is_mine_and_competitor_is_higher(): void
    {
        $w = $this->watch();
        Http::fake(['sellingpartnerapi-eu.amazon.com/batches/*' => Http::response($this->offers(true, 10.99, 9.90))]);
        app(BuyBoxWatcher::class)->run();
        $this->assertSame('10.98', $w->fresh()->suggested_price);
    }

    public function test_auto_applies_within_daily_limit_and_blocks_beyond(): void
    {
        $w = $this->watch(['auto' => true, 'max_daily_change' => 10]);
        Http::fake([
            'sellingpartnerapi-eu.amazon.com/batches/*' => Http::sequence()->push($this->offers(false, 9.49))->push($this->offers(false, 6.99)),
            'sellingpartnerapi-eu.amazon.com/listings/*' => Http::response(['status' => 'ACCEPTED', 'issues' => []]),
        ]);

        app(BuyBoxWatcher::class)->run();
        $this->assertSame('9.48', ChannelPrice::sole()->price);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['patches'][0]['value'][0]['our_price'][0]['schedule'][0]['value_with_tax'] === 9.48);

        // Zweiter Lauf: 8,00 € Minimum, aber mehr als 10 % unter dem Tagesstart (9,90) -> blockiert
        app(BuyBoxWatcher::class)->run();
        $this->assertSame('9.48', ChannelPrice::sole()->price);
        $this->assertStringContainsString('über der Grenze', $w->fresh()->last_result['note']);
        $this->assertSame('8.00', $w->fresh()->suggested_price);
    }

    public function test_page_creates_watch_with_suggested_minimum_and_applies(): void
    {
        Livewire::test(PriceWatches::class)
            ->set('search', 'Sleeves')->assertSee('Überwachen')
            ->call('startNew', $this->product->variants[0]->id)
            ->assertSet('form.min_price', fn ($v) => $v !== '')
            ->call('save')->assertHasNoErrors();
        $w = PriceWatch::sole();
        // Kein Verlust: EK 3,00 / (1/1,19 - 15 %) = 4,33 -> 4,99
        $this->assertSame('4.99', $w->min_price);

        $w->update(['suggested_price' => 9.79]);
        Http::fake(['sellingpartnerapi-eu.amazon.com/listings/*' => Http::response(['status' => 'ACCEPTED', 'issues' => []])]);
        Livewire::test(PriceWatches::class)->call('apply', $w->id)->assertSet('error', null);
        $this->assertSame('9.79', ChannelPrice::sole()->price);
        $this->get('/preiswaechter')->assertOk()->assertSee('Preiswächter');
    }
}
