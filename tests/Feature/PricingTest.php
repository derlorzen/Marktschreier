<?php

namespace Tests\Feature;

use App\Livewire\Products\Edit;
use App\Livewire\Products\Index;
use App\Models\ChangeLog;
use App\Models\Channel;
use App\Models\ChannelPrice;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\User;
use App\Services\Pricing\PriceCalculator;
use App\Services\Shopify\ProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\FakesApis;
use Tests\TestCase;

class PricingTest extends TestCase
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
        $this->product->variants[0]->update(['cost_net' => 3.20]);
        $this->product->variants[1]->update(['cost_net' => null]);
        $this->actingAs(User::factory()->create());
    }

    public function test_rounding_to_ending_never_goes_below_raw_price(): void
    {
        $this->assertSame(12.99, PriceCalculator::roundToEnding(12.31, '99'));
        $this->assertSame(13.99, PriceCalculator::roundToEnding(12.995, '99'));
        $this->assertSame(12.90, PriceCalculator::roundToEnding(12.10, '90'));
        $this->assertSame(13.00, PriceCalculator::roundToEnding(12.01, '00'));
        $this->assertSame(12.00, PriceCalculator::roundToEnding(12.00, '00'));
    }

    public function test_calculated_price_reaches_target_margin_and_min_profit(): void
    {
        $calc = new PriceCalculator(19);
        $amazon = Channel::where('key', 'amazon')->first(); // 15 %
        $rule = PriceRule::with('lines')->first();          // 30 %, Endung 99, min 1 €

        $price = $calc->calculate(3.20, $amazon, $rule);
        // Rohpreis: 3,20 / (0,7/1,19 - 0,15) = 7,30 -> 7,99
        $this->assertSame(7.99, $price);
        $this->assertGreaterThanOrEqual(30.0, $calc->margin($price, 3.20, $amazon));

        $rule->min_profit = 5;
        $price = $calc->calculate(3.20, $amazon, $rule);
        $this->assertGreaterThanOrEqual(5.0, $calc->profit($price, 3.20, $amazon));

        $this->assertNull($calc->calculate(null, $amazon, $rule));
    }

    public function test_apply_rule_in_article_sets_channel_prices_once_and_marks_shopify_pending(): void
    {
        $rule = PriceRule::first();
        $shop = Channel::where('key', 'shopify')->first();
        $amazon = Channel::where('key', 'amazon')->first();
        $variant = $this->product->variants[0];

        Livewire::test(Edit::class, ['product' => $this->product])
            ->set('tab', 'preise')
            ->set('ruleChannels', [$shop->id, $amazon->id])
            ->call('previewRule')
            ->assertSee('Kein EK aus Billbee')
            ->call('applyRule')
            ->assertSet('notice', fn ($n) => str_contains($n, '2 Preise geändert'));

        $cp = ChannelPrice::where('variant_id', $variant->id)->where('channel_id', $amazon->id)->sole();
        $this->assertSame('7.99', $cp->price);
        $this->assertSame('rule', $cp->source);
        $this->assertSame(Product::STATUS_PENDING, $this->product->fresh()->push_status);
        $this->assertNotSame('9.90', $variant->fresh()->price);
        $this->assertTrue(ChangeLog::where('field', 'Preis Amazon')->where('source', 'rule')->exists());
        // Nichts bleibt hängen: kein Verweis auf das Regelwerk an Produkt oder Variante.
        $this->assertArrayNotHasKey('price_rule_id', $variant->fresh()->getAttributes());
    }

    public function test_only_increase_skips_reductions(): void
    {
        $amazon = Channel::where('key', 'amazon')->first();
        ChannelPrice::create(['variant_id' => $this->product->variants[0]->id, 'channel_id' => $amazon->id, 'price' => 19.99]);

        Livewire::test(Edit::class, ['product' => $this->product])
            ->set('tab', 'preise')->set('ruleChannels', [$amazon->id])->set('onlyIncrease', true)
            ->call('previewRule')->assertSee('Wäre eine Senkung')
            ->call('applyRule');

        $this->assertSame('19.99', ChannelPrice::sole()->price);
    }

    public function test_bulk_rule_needs_preview_and_applies(): void
    {
        $amazon = Channel::where('key', 'amazon')->first();

        Livewire::test(Index::class)
            ->set('selected', [$this->product->id])
            ->set('bulkAction', 'price_rule')
            ->set('bulkRuleChannels', [$amazon->id])
            ->call('runBulk')
            ->tap(fn () => $this->assertSame(0, ChannelPrice::count()))
            ->call('previewBulkRule')
            ->assertSet('bulkPreview.changed', 1)
            ->assertSee('1 Preise ändern sich')
            ->call('runBulk')
            ->assertSet('notice', fn ($n) => str_contains($n, '1 Preise geändert'));

        $this->assertSame(1, ChannelPrice::count());
    }

    public function test_manual_channel_price_and_reset(): void
    {
        $ebay = Channel::where('key', 'ebay')->first();
        $v = $this->product->variants[0];

        Livewire::test(Edit::class, ['product' => $this->product])
            ->set('tab', 'preise')
            ->set("channelPriceInputs.{$v->id}.{$ebay->id}", '11,49')
            ->call('saveChannelPrices')->assertHasNoErrors();
        $this->assertSame('11.49', ChannelPrice::sole()->price);
        $this->assertFalse($this->product->fresh('variants')->hasPendingChanges());

        Livewire::test(Edit::class, ['product' => $this->product])
            ->set('tab', 'preise')
            ->set("channelPriceInputs.{$v->id}.{$ebay->id}", '')
            ->call('saveChannelPrices');
        $this->assertSame(0, ChannelPrice::count());
        $this->assertSame(9.90, $v->fresh()->priceOn($ebay));
    }

    public function test_pricing_page_saves_channels_and_rules(): void
    {
        $amazon = Channel::where('key', 'amazon')->first();
        $rule = PriceRule::first();

        Livewire::test(\App\Livewire\Pricing::class)
            ->set("channels.{$amazon->id}.fee_percent", '15,45')
            ->set("rules.{$rule->id}.lines.{$amazon->id}.target_margin", '25')
            ->set("rules.{$rule->id}.name", 'Eigenmarke')
            ->call('save')->assertHasNoErrors()
            ->assertSee('Gewinn');

        $this->assertSame('15.450', $amazon->fresh()->fee_percent);
        $this->assertSame('Eigenmarke', $rule->fresh()->name);
        $this->assertSame('25.00', $rule->lines()->where('channel_id', $amazon->id)->value('target_margin'));
        $this->get('/preise')->assertOk();
    }
}
