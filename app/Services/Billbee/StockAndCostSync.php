<?php

namespace App\Services\Billbee;

use App\Models\Setting;
use App\Models\SyncRun;
use App\Models\Variant;

/**
 * Holt Einkaufspreis und Bestand aus Billbee und schreibt sie an die passenden Varianten.
 * Zuordnung über SKU, sonst über EAN/GTIN. Billbee bleibt führend, hier wird nur gelesen.
 */
class StockAndCostSync
{
    public function __construct(private BillbeeClient $client)
    {
    }

    public function run(?SyncRun $run = null): array
    {
        $run ??= SyncRun::start(SyncRun::TYPE_BILLBEE);
        $stats = ['billbee_products' => 0, 'matched' => 0, 'by_sku' => 0, 'by_ean' => 0, 'unmatched' => 0, 'ambiguous' => 0];
        $unmatched = [];

        $mode = Setting::get('billbee.cost_price_mode', 'net');
        $defaultVat = (float) Setting::get('billbee.vat_rate', 19);

        // Nachschlagetabellen einmal aufbauen statt pro Produkt zu suchen.
        $bySku = [];
        $byEan = [];
        foreach (Variant::query()->select(['id', 'sku', 'barcode'])->cursor() as $v) {
            if ($v->sku) {
                $bySku[mb_strtolower(trim($v->sku))][] = $v->id;
            }
            if ($v->barcode) {
                $byEan[preg_replace('/\D/', '', $v->barcode)][] = $v->id;
            }
        }

        try {
            foreach ($this->client->allProducts() as $bb) {
                $stats['billbee_products']++;
                $sku = mb_strtolower(trim((string) ($bb['SKU'] ?? '')));
                $ean = preg_replace('/\D/', '', (string) ($bb['EAN'] ?? ''));

                $ids = ($sku !== '' ? ($bySku[$sku] ?? []) : []);
                $how = 'by_sku';
                if ($ids === [] && $ean !== '') {
                    $ids = $byEan[$ean] ?? [];
                    $how = 'by_ean';
                }
                if ($ids === []) {
                    $stats['unmatched']++;
                    if (count($unmatched) < 50) {
                        $unmatched[] = $bb['SKU'] ?? ('Billbee-ID '.($bb['Id'] ?? '?'));
                    }

                    continue;
                }
                if (count($ids) > 1) {
                    // Dieselbe SKU an mehreren Varianten: nicht raten, sondern melden.
                    $stats['ambiguous']++;

                    continue;
                }

                $cost = $bb['CostPrice'] ?? null;
                if ($cost !== null && $mode === 'gross') {
                    $vat = (float) ($bb['Vat1Rate'] ?? $defaultVat) ?: $defaultVat;
                    $cost = (float) $cost / (1 + $vat / 100);
                }

                Variant::whereKey($ids[0])->update([
                    'billbee_id' => $bb['Id'] ?? null,
                    'cost_net' => $cost !== null ? round((float) $cost, 4) : null,
                    'stock' => isset($bb['StockCurrent']) ? (int) floor((float) $bb['StockCurrent']) : null,
                    'stock_locations' => json_encode(collect($bb['Stocks'] ?? [])->map(fn ($s) => [
                        'name' => $s['Name'] ?? ('Lager '.($s['StockId'] ?? '?')),
                        'quantity' => (int) floor((float) ($s['StockCurrent'] ?? 0)),
                        'reserved' => (int) floor((float) ($s['UnfulfilledAmount'] ?? 0)),
                    ])->values()->all() ?: null),
                    'billbee_synced_at' => now(),
                ]);
                $stats['matched']++;
                $stats[$how]++;
            }
        } catch (\Throwable $e) {
            $run->fail($e->getMessage(), $stats);
            throw $e;
        }

        $stats['unmatched_examples'] = $unmatched;
        $run->finish($stats, $stats['unmatched'] ? $stats['unmatched'].' Billbee-Artikel ohne passende Variante' : null);

        return $stats;
    }
}
