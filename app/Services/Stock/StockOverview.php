<?php

namespace App\Services\Stock;

use App\Models\Channel;
use App\Models\Variant;
use Illuminate\Support\Collection;

/**
 * Bestand je Kanal: was Billbee hat, was bei FBA liegt und was eBay und Amazon gerade anzeigen.
 * Zeigt ein Marktplatz mehr an, als im eigenen Lager liegt, droht ein Überverkauf.
 */
class StockOverview
{
    /** @return Collection<int, array> eine Zeile je Variante */
    public function rows(): Collection
    {
        $channels = Channel::whereIn('key', ['ebay', 'amazon', 'tiktok'])->get()->keyBy('id');

        return Variant::with(['product:id,title,status', 'listings'])->get()->map(function (Variant $v) use ($channels) {
            $own = $v->stock;                       // Billbee
            $fba = null;
            $shown = [];
            $warnings = [];
            foreach ($v->listings as $l) {
                $channel = $channels->get($l->channel_id);
                if (! $channel || ! in_array($l->status, ['active', 'pending', 'error'], true)) {
                    continue;
                }
                if ($l->fulfillment === 'FBA') {
                    $fba = $l->fba;

                    continue;
                }
                if ($l->remote_quantity === null) {
                    continue;
                }
                $shown[$channel->key] = $l->remote_quantity;
                if ($own !== null && $l->remote_quantity > max(0, $own)) {
                    $warnings[] = "{$channel->name} zeigt {$l->remote_quantity}, im Lager sind {$own}";
                }
            }

            return [
                'variant' => $v,
                'own' => $own,
                'locations' => $v->stock_locations ?? [],
                'fba' => $fba,
                'shown' => $shown,
                'warnings' => $warnings,
            ];
        });
    }

    /** Für Hinweise: alle Varianten mit möglichem Überverkauf */
    public function oversold(): Collection
    {
        return $this->rows()->filter(fn ($r) => $r['warnings'] !== [])->values();
    }
}
