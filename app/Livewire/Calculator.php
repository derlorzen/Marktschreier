<?php

namespace App\Livewire;

use App\Models\Channel;
use App\Models\Product;
use App\Models\Variant;
use App\Services\Pricing\PriceCalculator;
use App\Services\Sales\SalesStats;
use App\Support\Format;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Was-wäre-wenn-Rechner: Heute gegen einen Plan mit anderem Preis, Einkauf, Stückzahl oder Werbebudget.
 */
#[Layout('layouts.app')]
#[Title('Was-wäre-wenn-Rechner')]
class Calculator extends Component
{
    #[Url(as: 'variante', except: null)]
    public ?int $variantId = null;

    public ?int $channelId = null;

    public string $search = '';

    /** @var array{price: string, cost: string, units: string, ads: string} */
    public array $now = ['price' => '19,99', 'cost' => '6,00', 'units' => '30', 'ads' => '0'];

    public array $plan = ['price' => '19,99', 'cost' => '6,00', 'units' => '30', 'ads' => '0'];

    public string $targetMargin = '30';

    public function mount(): void
    {
        $this->channelId ??= Channel::active()->value('id');
        if ($this->variantId) {
            $this->pick($this->variantId);
        }
    }

    public function pick(int $variantId): void
    {
        $v = Variant::with('product', 'channelPrices')->find($variantId);
        if (! $v) {
            return;
        }
        $this->variantId = $v->id;
        $this->search = '';
        $this->fillFromVariant($v);
    }

    public function updatedChannelId(): void
    {
        if ($this->variantId && ($v = Variant::with('channelPrices')->find($this->variantId))) {
            $this->fillFromVariant($v);
        }
    }

    private function fillFromVariant(Variant $v): void
    {
        $channel = Channel::find($this->channelId);
        $sold = app(SalesStats::class)->period(30)['variants'][$v->id]['by_channel'][$this->channelId] ?? 0;
        $values = [
            'price' => Format::decimalForInput($channel ? $v->priceOn($channel) : $v->price),
            'cost' => Format::decimalForInput($v->cost_net ?? 0),
            'units' => (string) (int) round($sold),
            'ads' => '0',
        ];
        $this->now = $values;
        $this->plan = $values;
    }

    public function clearVariant(): void
    {
        $this->variantId = null;
    }

    private function num(?string $v): float
    {
        return (float) (Format::parseDecimal($v) ?? 0);
    }

    /** @return array<string, float|null> */
    private function evaluate(array $s, Channel $channel, PriceCalculator $calc): array
    {
        $price = $this->num($s['price']);
        $cost = $this->num($s['cost']);
        $units = max(0, $this->num($s['units']));
        $ads = max(0, $this->num($s['ads']));
        $unitProfit = $calc->profit($price, $cost, $channel);
        $net = $price / (1 + $calc->vat() / 100);
        $feeShare = (float) $channel->fee_percent / 100;
        $fixed = $calc->fixedCosts($channel);
        $den = 1 / (1 + $calc->vat() / 100) - $feeShare;
        $tm = $this->num($this->targetMargin) / 100;
        $denTarget = (1 - $tm) / (1 + $calc->vat() / 100) - $feeShare;

        return [
            'unit_profit' => $unitProfit,
            'margin' => $net > 0 ? $unitProfit / $net * 100 : null,
            'month_profit' => $units * $unitProfit - $ads,
            'revenue' => $units * $price,
            'break_even_acos' => $price > 0 ? max(0, $unitProfit) / $price * 100 : null,
            'acos' => $units * $price > 0 && $ads > 0 ? $ads / ($units * $price) * 100 : null,
            'min_price' => $den > 0.01 ? ($cost + $fixed) / $den : null,
            'target_price' => $denTarget > 0.01 ? PriceCalculator::roundToEnding(($cost + $fixed) / $denTarget, '99') : null,
            'units' => $units,
            'ads' => $ads,
        ];
    }

    public function render(PriceCalculator $calc)
    {
        $channels = Channel::orderBy('position')->get();
        $channel = $channels->firstWhere('id', $this->channelId) ?? $channels->first();
        $a = $this->evaluate($this->now, $channel, $calc);
        $b = $this->evaluate($this->plan, $channel, $calc);
        // Wie viele Stück braucht der Plan, um den heutigen Monatsgewinn zu halten?
        $unitsToMatch = $b['unit_profit'] > 0 ? ($a['month_profit'] + $b['ads']) / $b['unit_profit'] : null;

        $variant = $this->variantId ? Variant::with('product')->find($this->variantId) : null;
        $results = trim($this->search) !== ''
            ? Product::search($this->search)->with('variants')->limit(6)->get()->flatMap(fn ($p) => $p->variants->map(fn ($v) => ['id' => $v->id, 'label' => $p->title.($v->label() !== 'Standard' ? ' ('.$v->label().')' : '')]))->take(10)
            : collect();

        return view('livewire.calculator', compact('channels', 'channel', 'a', 'b', 'unitsToMatch', 'variant', 'results'));
    }
}
