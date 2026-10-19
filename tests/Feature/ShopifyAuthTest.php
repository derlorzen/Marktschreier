<?php

namespace Tests\Feature;

use App\Services\Shopify\ShopifyClient;
use App\Services\Shopify\ShopifyException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyAuthTest extends TestCase
{
    use RefreshDatabase;

    private function client(): ShopifyClient
    {
        return new ShopifyClient('shop.myshopify.com', null, '2026-10', fn () => null, 'cid', 'csecret');
    }

    public function test_client_credentials_token_is_fetched_cached_and_refreshed_on_401(): void
    {
        Http::fake([
            'shop.myshopify.com/admin/oauth/access_token' => Http::sequence()
                ->push(['access_token' => 'tok1', 'scope' => 'read_products', 'expires_in' => 86399])
                ->push(['access_token' => 'tok2', 'scope' => 'read_products', 'expires_in' => 86399]),
            'shop.myshopify.com/admin/api/*' => Http::sequence()
                ->push(['data' => ['shop' => ['name' => 'A']]])
                ->push(['data' => ['shop' => ['name' => 'B']]])
                ->push('', 401)
                ->push(['data' => ['shop' => ['name' => 'C']]]),
        ]);

        $c = $this->client();
        $this->assertSame('A', $c->shopName());
        $this->assertSame('B', $c->shopName());
        $this->assertSame('C', $c->shopName());

        Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth/access_token') && $r['grant_type'] === 'client_credentials' && $r['client_id'] === 'cid');
        $this->assertSame(2, Http::recorded(fn ($r) => str_contains($r->url(), 'oauth'))->count());
        Http::assertSent(fn ($r) => $r->hasHeader('X-Shopify-Access-Token', 'tok2'));
    }

    public function test_foreign_org_gives_clear_message(): void
    {
        Http::fake(['shop.myshopify.com/*' => Http::response(['error' => 'shop_not_permitted'], 400)]);

        $this->expectException(ShopifyException::class);
        $this->expectExceptionMessage('derselben Organisation');
        $this->client()->shopName();
    }
}
