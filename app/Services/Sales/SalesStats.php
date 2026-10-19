<?php

namespace App\Services\Sales;

use App\Models\Channel;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Umsatz und Gewinn aus den Billbee-Bestellungen.
 *
 * Gewinn je Bestellung = Netto-Umsatz der Positionen - EK × Menge - Kanalgebühr in % vom Brutto
 *                        - feste Gebühr - Versand und Verpackung (beides einmal je Bestellung)
 * Positionen ohne EK zählen zum Umsatz, aber nicht zum Gewinn.
 */
class SalesStats
{
    /** @var array<string, array> */
    private array $cache = [];

    /**
     * @return array{total: array, channels: array<int|string, array>, variants: array<int, array>, days: int}
     */
    public function period(int $days, ?Carbon $until = null): array
    {
        $until ??= now();
        $key = $days.'|'.$until->format('Y-m-d H');
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $from = $until->copy()->subDays($days);
        $channels = Channel::all()->keyBy('id');

        $blank = fn () => ['orders' => 0, 'quantity' => 0.0, 'revenue_gross' => 0.0, 'revenue_net' => 0.0, 'profit' => 0.0, 'items_without_cost' => 0];
        $total = $blank();
        $byChannel = [];
        $byVariant = [];

        Order::sold()->whereBetween('ordered_at', [$from, $until])->with('items')->chunkById(500, function (Collection $orders) use (&$total, &$byChannel, &$byVariant, $channels, $blank) {
            foreach ($orders as $order) {
                $channel = $order->channel_id ? $channels->get($order->channel_id) : null;
                $ck = $order->channel_id ?? 'none';
                $byChannel[$ck] ??= $blank();

                $lines = $order->items;
                $lineCount = max(1, $lines->count());
                $fixedPerLine = $channel ? ((float) $channel->fee_fixed + (float) $channel->shipping_cost) / $lineCount : 0.0;
                $feeShare = $channel ? (float) $channel->fee_percent / 100 : 0.0;

                foreach ([&$total, &$byChannel[$ck]] as &$agg) {
                    $agg['orders']++;
                }
                unset($agg);

                foreach ($lines as $item) {
                    $gross = (float) $item->total_gross;
                    $net = $gross - (float) $item->tax_amount;
                    $qty = (float) $item->quantity;
                    $profit = $item->cost_net === null ? null
                        : $net - (float) $item->cost_net * $qty - $gross * $feeShare - $fixedPerLine;

                    $targets = [&$total, &$byChannel[$ck]];
                    if ($item->variant_id) {
                        $byVariant[$item->variant_id] ??= $blank() + ['by_channel' => []];
                        $byVariant[$item->variant_id]['by_channel'][$ck] = ($byVariant[$item->variant_id]['by_channel'][$ck] ?? 0) + $qty;
                        $targets[] = &$byVariant[$item->variant_id];
                    }
                    foreach ($targets as &$agg) {
                        $agg['quantity'] += $qty;
                        $agg['revenue_gross'] += $gross;
                        $agg['revenue_net'] += $net;
                        if ($profit === null) {
                            $agg['items_without_cost']++;
                        } else {
                            $agg['profit'] += $profit;
                        }
                    }
                    unset($agg, $targets);
                }
            }
        });

        foreach ($byVariant as &$v) {
            $v['orders'] = null; // je Variante nicht sinnvoll
        }
        unset($v);

        return $this->cache[$key] = ['total' => $total, 'channels' => $byChannel, 'variants' => $byVariant, 'days' => $days];
    }

    /** Verkaufte Stück je Tag einer Variante über alle Kanäle. */
    public function dailyRate(int $variantId, int $days = 30): float
    {
        return ($this->period($days)['variants'][$variantId]['quantity'] ?? 0) / max(1, $days);
    }

    /** @return list<array{date: string, revenue: float, profit: float}> Tageswerte für ein einfaches Diagramm */
    public function daily(int $days): array
    {
        $from = now()->subDays($days - 1)->startOfDay();
        $rows = [];
        for ($d = 0; $d < $days; $d++) {
            $rows[$from->copy()->addDays($d)->format('Y-m-d')] = ['revenue' => 0.0];
        }
        Order::sold()->where('ordered_at', '>=', $from)->get(['ordered_at', 'total_gross'])->each(function ($o) use (&$rows) {
            $k = $o->ordered_at->timezone('Europe/Berlin')->format('Y-m-d');
            if (isset($rows[$k])) {
                $rows[$k]['revenue'] += (float) $o->total_gross;
            }
        });

        return collect($rows)->map(fn ($r, $date) => ['date' => $date] + $r)->values()->all();
    }
}
