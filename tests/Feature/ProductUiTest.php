<?php

namespace Tests\Feature;

use App\Livewire\Products\Edit;
use App\Livewire\Products\Index;
use App\Livewire\Settings\Shopify as ShopifySettings;
use App\Models\ChangeLog;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Shopify\ProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\FakesApis;
use Tests\TestCase;

class ProductUiTest extends TestCase
{
    use FakesApis, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindApiClients();
        $this->queueShopify($this->productsPage([
            $this->shopifyNode(),
            $this->shopifyNode(['id' => 'gid://shopify/Product/2', 'title' => 'Deck Box', 'vendor' => 'Ultra Pro', 'tags' => []],
                [$this->shopifyVariant(['id' => 'gid://shopify/ProductVariant/21', 'sku' => 'UP-DB', 'barcode' => null])]),
        ]));
        app(ProductImporter::class)->run();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        auth()->logout();
        $this->get('/artikel')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Anmelden');
    }

    public function test_list_search_and_filters(): void
    {
        $this->get('/artikel')->assertOk()->assertSee('Sleeves Matt 100 Stück')->assertSee('Deck Box');

        Livewire::test(Index::class)
            ->set('search', 'JM-SLV-RED')->assertSee('Sleeves Matt')->assertDontSee('Deck Box')
            ->set('search', '')->set('missing', 'gtin')->assertSee('Deck Box')->assertDontSee('Sleeves Matt')
            ->set('missing', '')->set('vendor', 'Ultra Pro')->assertSee('Deck Box')->assertDontSee('Sleeves Matt');
    }

    public function test_bulk_tag_marks_products_pending_but_keeps_conflicts(): void
    {
        $deck = Product::where('title', 'Deck Box')->first();
        $deck->update(['push_status' => Product::STATUS_CONFLICT]);

        Livewire::test(Index::class)
            ->set('selected', Product::pluck('id')->all())
            ->set('bulkAction', 'tag_add')->set('bulkValue', 'Neuheit')
            ->call('runBulk')
            ->assertSet('notice', fn ($n) => str_starts_with($n, '2 von 2'));

        $sleeves = Product::where('title', 'like', 'Sleeves%')->first();
        $this->assertContains('Neuheit', $sleeves->tags);
        $this->assertSame(Product::STATUS_PENDING, $sleeves->push_status);
        $this->assertSame(Product::STATUS_CONFLICT, $deck->fresh()->push_status);
    }

    public function test_edit_saves_logs_and_validates(): void
    {
        $product = Product::where('title', 'like', 'Sleeves%')->first();
        $variant = $product->variants[0];

        Livewire::test(Edit::class, ['product' => $product])
            ->set('form.title', 'Sleeves Matt 100er')
            ->set("variants.{$variant->id}.price", '10,50')
            ->set("variants.{$variant->id}.barcode", '4260000000012')
            ->call('save')
            ->assertHasErrors("variants.{$variant->id}.barcode")
            ->set("variants.{$variant->id}.barcode", '4006381333931')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('notice', '3 Änderungen gespeichert. Noch nicht in Shopify.');

        $product->refresh();
        $this->assertSame('Sleeves Matt 100er', $product->title);
        $this->assertSame('10.50', $variant->fresh()->price);
        $this->assertSame(Product::STATUS_PENDING, $product->push_status);
        $this->assertSame(3, ChangeLog::where('source', 'user')->where('user_id', $this->user->id)->count());
    }

    public function test_duplicate_sku_is_rejected(): void
    {
        $product = Product::where('title', 'like', 'Sleeves%')->first();

        Livewire::test(Edit::class, ['product' => $product])
            ->set("variants.{$product->variants[0]->id}.sku", 'UP-DB')
            ->call('save')
            ->assertHasErrors("variants.{$product->variants[0]->id}.sku");
    }

    public function test_push_from_edit_and_revert(): void
    {
        $product = Product::where('title', 'like', 'Sleeves%')->first();
        $this->queueShopify(['data' => ['productUpdate' => ['product' => ['id' => 'x'], 'userErrors' => []]]]);

        Livewire::test(Edit::class, ['product' => $product])
            ->set('form.vendor', 'Neue Marke')
            ->call('push')
            ->assertSet('notice', 'Zu Shopify übertragen.');
        $this->assertSame(Product::STATUS_SYNCED, $product->fresh()->push_status);

        Livewire::test(Edit::class, ['product' => $product->fresh()])
            ->set('form.title', 'Vertippt')
            ->call('save')
            ->call('revert')
            ->assertSet('form.title', 'Sleeves Matt 100 Stück');
        $this->assertSame(Product::STATUS_SYNCED, $product->fresh()->push_status);
    }

    public function test_attributes_are_saved(): void
    {
        $product = Product::first();
        Livewire::test(Edit::class, ['product' => $product])
            ->call('addAttribute')
            ->set('attrs.0.key', 'Material')->set('attrs.0.value', 'Polypropylen')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('Polypropylen', $product->attributeValues()->where('key', 'Material')->value('value'));
        $this->assertFalse($product->fresh('variants')->hasPendingChanges());
    }

    public function test_open_articles_appear_as_tabs(): void
    {
        [$a, $b] = Product::orderBy('id')->get();
        $this->get(route('products.edit', $a))->assertOk();
        $this->get(route('products.edit', $b))->assertOk()->assertSee('Geöffnete Artikel', false);
        $this->assertSame([$a->id, $b->id], session('open_products'));

        $this->from(route('products.edit', $b))->get(route('products.close', $b))->assertRedirect(route('products.edit', $a));
        $this->assertSame([$a->id], session('open_products'));
    }

    public function test_settings_store_secrets_encrypted_and_keep_them_when_left_empty(): void
    {
        Livewire::test(ShopifySettings::class)
            ->set('shopifyShop', 'https://Johtomarket.myshopify.com/')
            ->set('shopifyToken', 'shpat_secret')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('johtomarket.myshopify.com', Setting::get('shopify.shop'));
        $this->assertSame('shpat_secret', Setting::get('shopify.token'));
        $this->assertStringNotContainsString('shpat_secret', Setting::where('key', 'shopify.token')->value('value'));

        Livewire::test(ShopifySettings::class)->assertSet('shopifyToken', '')->call('save');
        $this->assertSame('shpat_secret', Setting::get('shopify.token'));
    }

    public function test_pages_render(): void
    {
        $this->get('/abgleich')->assertOk()->assertSee('Protokoll');
        $this->get('/einstellungen')->assertOk()->assertSee('Billbee');
        foreach (['allgemein', 'benutzer', 'zeitplan', 'shopify', 'billbee', 'ebay', 'amazon', 'konto'] as $page) {
            $this->get('/einstellungen/'.$page)->assertOk();
        }
        $this->get(route('products.edit', Product::first()).'?tab=verlauf')->assertOk();
    }
}
