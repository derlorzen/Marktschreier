<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelPrice;
use App\Models\Listing;
use App\Models\MarketplaceConnection;
use App\Models\Product;
use App\Models\User;
use App\Services\Marketplaces\MarketplaceManager;
use App\Services\Marketplaces\TikTok\TikTokClient;
use App\Services\Shopify\ProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesApis;
use Tests\TestCase;

class TikTokTest extends TestCase
{
    use FakesApis, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['marktschreier.tiktok.app_key' => 'appkey', 'marktschreier.tiktok.app_secret' => 'geheim']);
        $this->bindApiClients();
        $this->queueShopify($this->productsPage([$this->shopifyNode()]));
        app(ProductImporter::class)->run();
        $this->actingAs(User::factory()->create());
    }

    public function test_signature_follows_tiktok_rules(): void
    {
        $sig = TikTokClient::sign('/product/202309/products/search', ['timestamp' => 1700000000, 'app_key' => 'appkey', 'sign' => 'x', 'access_token' => 'y', 'shop_cipher' => 'C1'], '{"a":1}', 'geheim');
        $expected = hash_hmac('sha256', 'geheim/product/202309/products/searchapp_keyappkeyshop_cipherC1timestamp1700000000{"a":1}geheim', 'geheim');
        $this->assertSame($expected, $sig);
    }

    public function test_connect_sync_and_push_prices(): void
    {
        Queue::fake();
        Http::fake([
            'auth.tiktok-shops.com/api/v2/token/get*' => Http::response(['code' => 0, 'data' => ['access_token' => 'at', 'access_token_expire_in' => time() + 86400, 'refresh_token' => 'rt']]),
            'open-api.tiktokglobalshop.com/authorization/202309/shops*' => Http::response(['code' => 0, 'data' => ['shops' => [['cipher' => 'ROW_1', 'name' => 'Johto TikTok', 'region' => 'DE']]]]),
            'open-api.tiktokglobalshop.com/product/202309/products/search*' => Http::response(['code' => 0, 'data' => ['products' => [
                ['id' => '1729', 'title' => 'Sleeves', 'status' => 'ACTIVATE', 'skus' => [
                    ['id' => 's1', 'seller_sku' => 'JM-SLV-BLK', 'price' => ['currency' => 'EUR', 'sale_price' => '10.99']],
                    ['id' => 's2', 'seller_sku' => 'FREMD', 'price' => ['currency' => 'EUR', 'sale_price' => '1.00']],
                ]],
            ], 'next_page_token' => '']]),
            'open-api.tiktokglobalshop.com/product/202309/products/1729/prices/update*' => Http::response(['code' => 0, 'data' => []]),
        ]);

        $this->get(route('settings.tiktok.connect'))->assertRedirect();
        $state = session('oauth_state.tiktok');
        $this->get(route('settings.tiktok.callback', ['code' => 'abc', 'state' => $state]))->assertRedirect(route('settings.tiktok'));
        $c = MarketplaceConnection::for('tiktok')->fresh();
        $this->assertTrue($c->isConnected());
        $this->assertSame('Johto TikTok', $c->account_name);
        $this->assertSame('ROW_1', $c->secrets()['shop_cipher']);

        $stats = app(MarketplaceManager::class)->syncListings('tiktok');
        $this->assertSame(['listings' => 1, 'matched' => 1, 'unmatched' => 1], $stats);
        $tiktok = Channel::where('key', 'tiktok')->first();
        $v = Product::sole()->variants[0];
        $this->assertSame('10.99', ChannelPrice::where('variant_id', $v->id)->where('channel_id', $tiktok->id)->value('price'));

        app(\App\Services\ProductEditor::class)->setChannelPrice($v, $tiktok, '11.49');
        $this->assertSame(['ok' => 1, 'failed' => 0], app(MarketplaceManager::class)->pushPrices('tiktok'));

        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), 'prices/update')) {
                return false;
            }
            parse_str(parse_url($r->url(), PHP_URL_QUERY), $q);
            $expected = TikTokClient::sign(parse_url($r->url(), PHP_URL_PATH), $q, $r->body(), 'geheim');

            return $q['sign'] === $expected && $q['shop_cipher'] === 'ROW_1' && $r->hasHeader('x-tts-access-token', 'at')
                && $r['skus'][0] === ['id' => 's1', 'price' => ['amount' => '11.49', 'currency' => 'EUR']];
        });
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'authorization/202309/shops') && ! str_contains($r->url(), 'shop_cipher'));
        $this->get('/einstellungen/tiktok')->assertOk()->assertSee('Johto TikTok');
    }
}
