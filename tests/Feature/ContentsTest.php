<?php

namespace Tests\Feature;

use App\Livewire\Products\Contents;
use App\Models\Channel;
use App\Models\ChannelContent;
use App\Models\Listing;
use App\Models\MarketplaceConnection;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Shopify\ProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\FakesApis;
use Tests\TestCase;

class ContentsTest extends TestCase
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

    public function test_ai_suggestion_is_inserted_and_saved_only_on_save(): void
    {
        Setting::put('ai.api_key', 'sk-ant-test');
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Hier: {"title": "Johto Market Sleeves matt 100 Stück", "bullets": ["MATT: blendfrei", "PASSFORM: Standardgröße"], "search_terms": "kartenhüllen tcg", "description_html": "<p>Matte Hüllen</p>"}']]])]);

        $c = Livewire::test(Contents::class, ['product' => $this->product])
            ->set('channelKey', 'amazon')->call('suggest')
            ->assertSet('form.title', 'Johto Market Sleeves matt 100 Stück')
            ->assertSet('form.bullets.1', 'PASSFORM: Standardgröße');
        $this->assertSame(0, ChannelContent::count());
        $c->call('save');

        $content = ChannelContent::sole();
        $this->assertSame('ai', $content->source);
        $this->assertSame(['MATT: blendfrei', 'PASSFORM: Standardgröße'], $content->bullets);
        Http::assertSent(fn (Request $r) => $r->hasHeader('x-api-key', 'sk-ant-test') && $r['model'] === 'claude-sonnet-5-5'
            && str_contains($r['messages'][0]['content'], 'Sleeves Matt 100 Stück') && ! str_contains($r['messages'][0]['content'], 'Kunde'));
    }

    public function test_title_limit_per_channel(): void
    {
        Livewire::test(Contents::class, ['product' => $this->product])
            ->set('channelKey', 'ebay')->set('form.title', str_repeat('x', 81))->call('save')
            ->assertHasErrors('form.title');
    }

    public function test_push_to_amazon_and_ebay(): void
    {
        foreach (['amazon' => ['seller_id' => 'A2ME'], 'ebay' => []] as $p => $extra) {
            $c = MarketplaceConnection::for($p);
            $c->storeSecrets(['refresh_token' => 'rt', 'access_token' => 'at', 'access_expires_at' => time() + 3600] + $extra);
            $c->update(['status' => 'connected']);
        }
        $v = $this->product->variants[0];
        Listing::create(['variant_id' => $v->id, 'channel_id' => Channel::where('key', 'amazon')->value('id'), 'external_id' => 'B0X', 'sku' => $v->sku, 'status' => 'active']);
        Listing::create(['variant_id' => $v->id, 'channel_id' => Channel::where('key', 'ebay')->value('id'), 'external_id' => '555', 'sku' => $v->sku, 'status' => 'active']);
        Http::fake([
            'sellingpartnerapi-eu.amazon.com/*' => Http::response(['status' => 'ACCEPTED', 'issues' => []]),
            'api.ebay.com/ws/api.dll' => Http::response('<?xml version="1.0"?><ReviseFixedPriceItemResponse xmlns="urn:ebay:apis:eBLBaseComponents"><Ack>Success</Ack></ReviseFixedPriceItemResponse>'),
        ]);

        Livewire::test(Contents::class, ['product' => $this->product])
            ->set('channelKey', 'amazon')->set('form.title', 'Amazon Titel')->set('form.bullets.0', 'Punkt eins')->call('push')
            ->assertSet('notice', '1 Angebote aktualisiert.')
            ->set('channelKey', 'ebay')->set('form.title', 'eBay Titel kurz')->call('push')
            ->assertSet('notice', '1 Angebote aktualisiert.');

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && collect($r['patches'])->pluck('path')->contains('/attributes/bullet_point')
            && $r['patches'][0]['value'][0]['value'] === 'Amazon Titel');
        Http::assertSent(fn (Request $r) => $r->hasHeader('X-EBAY-API-CALL-NAME', 'ReviseFixedPriceItem') && str_contains($r->body(), '<Title>eBay Titel kurz</Title>'));
        $this->get(route('products.edit', $this->product).'?tab=texte')->assertOk();
    }
}
