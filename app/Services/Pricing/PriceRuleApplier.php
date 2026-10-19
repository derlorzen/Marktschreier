<?php

namespace App\Services\Pricing;

use App\Models\Channel;
use App\Models\ChangeLog;
use App\Models\ChannelPrice;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\Variant;
use App\Services\ProductEditor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Wendet ein Regelwerk einmalig an. Es bleibt keine Verknüpfung am Artikel hängen:
 * danach dürfen Marktplätze oder eine Buy-Box-Regel den Preis wieder ändern.
 */
class PriceRuleApplier
{
    public function __construct(private PriceCalculator $calc, private ProductEditor $editor)
    {
    }

    /**
     * @param  Collection<int, Variant>  $variants  mit product und channelPrices geladen
     * @param  Collection<int, Channel>  $channels
     * @return list<array{variant: Variant, channel: Channel, old: float, new: ?float, old_profit: ?float, new_profit: ?float, skip: ?string}>
     */
    public function preview(Collection $variants, PriceRule $rule, Collection $channels, bool $onlyIncrease = false): array
    {
        $rule->loadMissing('lines');
        $rows = [];
        foreach ($variants as $variant) {
            $cost = $variant->cost_net !== null ? (float) $variant->cost_net : null;
            foreach ($channels as $channel) {
                $old = $variant->priceOn($channel);
                $new = $this->calc->calculate($cost, $channel, $rule);
                $skip = null;
                if ($cost === null) {
                    $skip = 'Kein EK aus Billbee';
                } elseif ($new === null) {
                    $skip = 'Regelwerk hat keine Zeile für '.$channel->name;
                } elseif (abs($new - $old) < 0.005) {
                    $skip = 'Preis bleibt gleich';
                } elseif ($onlyIncrease && $new < $old) {
                    $skip = 'Wäre eine Senkung';
                }
                $rows[] = [
                    'variant' => $variant, 'channel' => $channel, 'old' => $old, 'new' => $new,
                    'old_profit' => $this->calc->profit($old, $cost, $channel),
                    'new_profit' => $new === null ? null : $this->calc->profit($new, $cost, $channel),
                    'skip' => $skip,
                ];
            }
        }

        return $rows;
    }

    /** @return array{changed: int, up: int, down: int, skipped: int, worst: ?array} */
    public function summarize(array $rows): array
    {
        $todo = array_filter($rows, fn ($r) => $r['skip'] === null);
        $worst = null;
        foreach ($todo as $r) {
            $d = ($r['new'] - $r['old']) / max($r['old'], 0.01);
            if ($d < 0 && ($worst === null || $d < $worst['d'])) {
                $worst = ['d' => $d, 'name' => $r['variant']->product->title.' '.($r['variant']->label() !== 'Standard' ? '('.$r['variant']->label().')' : ''), 'channel' => $r['channel']->name];
            }
        }

        return [
            'changed' => count($todo),
            'up' => count(array_filter($todo, fn ($r) => $r['new'] > $r['old'])),
            'down' => count(array_filter($todo, fn ($r) => $r['new'] < $r['old'])),
            'skipped' => count($rows) - count($todo),
            'worst' => $worst,
        ];
    }

    /** Übernimmt alle nicht übersprungenen Zeilen. Shopify-Preise landen als offene Änderung. */
    public function apply(array $rows, PriceRule $rule, ?int $userId = null): int
    {
        $count = 0;
        DB::transaction(function () use ($rows, $rule, $userId, &$count) {
            $shopifyByProduct = [];
            foreach ($rows as $r) {
                if ($r['skip'] !== null) {
                    continue;
                }
                /** @var Variant $variant */
                $variant = $r['variant'];
                $channel = $r['channel'];
                if ($channel->isShopify()) {
                    $shopifyByProduct[$variant->product_id][$variant->id] = ['price' => number_format($r['new'], 2, '.', '')];

                    continue;
                }
                ChannelPrice::updateOrCreate(
                    ['variant_id' => $variant->id, 'channel_id' => $channel->id],
                    ['price' => $r['new'], 'source' => 'rule', 'price_rule_id' => $rule->id],
                );
                ChangeLog::record($variant->product, 'Preis '.$channel->name, $r['old'], number_format($r['new'], 2, '.', ''), 'rule', $variant, $userId);
                $count++;
            }
            foreach ($shopifyByProduct as $productId => $variantFields) {
                $count += $this->editor->update(Product::with('variants')->find($productId), [], $variantFields, $userId);
            }
        });

        return $count;
    }
}
