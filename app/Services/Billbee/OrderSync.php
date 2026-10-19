<?php

namespace App\Services\Billbee;

use App\Models\Channel;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SyncRun;
use App\Models\Variant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Liest Bestellungen aus Billbee für Umsatz, Gewinn und den Chancenfinder.
 * Übernimmt keine Kundendaten. Schreibt nichts nach Billbee.
 */
class OrderSync
{
    public const FIRST_IMPORT_DAYS = 365;

    public function __construct(private BillbeeClient $client)
    {
    }

    public function run(?SyncRun $run = null): array
    {
        $run ??= SyncRun::start(SyncRun::TYPE_BILLBEE_ORDERS);
        $stats = ['orders' => 0, 'items' => 0, 'items_unmatched' => 0, 'orders_without_channel' => 0];
        $platforms = [];

        $last = SyncRun::lastSuccessful(SyncRun::TYPE_BILLBEE_ORDERS);
        $modifiedSince = $last?->started_at?->copy()->subMinutes(10);
        $orderedSince = $modifiedSince ? null : now()->subDays(self::FIRST_IMPORT_DAYS);

        $channels = Channel::orderBy('position')->get();
        [$bySku, $byEan] = $this->variantLookup();

        try {
            foreach ($this->client->orders($orderedSince, $modifiedSince) as $bb) {
                if (empty($bb['BillBeeOrderId'])) {
                    continue;
                }
                $platform = $bb['Seller']['Platform'] ?? null;
                $channel = $channels->first(fn (Channel $c) => $c->matchesPlatform($platform));
                if (! $channel) {
                    $stats['orders_without_channel']++;
                    $key = $platform ?: 'unbekannt';
                    $platforms[$key] = ($platforms[$key] ?? 0) + 1;
                }

                DB::transaction(function () use ($bb, $platform, $channel, $bySku, $byEan, &$stats) {
                    $order = Order::updateOrCreate(['billbee_id' => $bb['BillBeeOrderId']], [
                        'order_number' => $bb['OrderNumber'] ?? null,
                        'platform' => $platform,
                        'shop_name' => $bb['Seller']['BillbeeShopName'] ?? null,
                        'channel_id' => $channel?->id,
                        'state' => $bb['State'] ?? null,
                        'ordered_at' => isset($bb['CreatedAt']) ? Carbon::parse($bb['CreatedAt']) : null,
                        'total_gross' => (float) ($bb['TotalCost'] ?? 0),
                        'shipping_gross' => (float) ($bb['ShippingCost'] ?? 0),
                        'currency' => $bb['Currency'] ?? 'EUR',
                        'billbee_modified_at' => isset($bb['LastModifiedAt']) ? Carbon::parse($bb['LastModifiedAt']) : null,
                    ]);
                    // EK vom ersten Import behalten, damit spätere EK-Änderungen alte Verkäufe nicht umbewerten.
                    $previousCosts = $order->items()->pluck('cost_net', 'sku')->all();
                    $order->items()->delete();

                    foreach ($bb['OrderItems'] ?? [] as $item) {
                        if (! empty($item['IsCoupon'])) {
                            continue;
                        }
                        $sku = trim((string) ($item['Product']['SKU'] ?? ''));
                        $ean = preg_replace('/\D/', '', (string) ($item['Product']['EAN'] ?? ''));
                        $variantId = ($sku !== '' ? ($bySku[mb_strtolower($sku)] ?? null) : null) ?? ($ean !== '' ? ($byEan[$ean] ?? null) : null);
                        $cost = $previousCosts[$sku] ?? ($variantId ? Variant::whereKey($variantId)->value('cost_net') : null);

                        OrderItem::create([
                            'order_id' => $order->id,
                            'variant_id' => $variantId,
                            'sku' => $sku ?: null,
                            'ean' => $ean ?: null,
                            'title' => mb_substr((string) ($item['Product']['Title'] ?? ''), 0, 255) ?: null,
                            'quantity' => (float) ($item['Quantity'] ?? 1),
                            'total_gross' => (float) ($item['TotalPrice'] ?? 0),
                            'tax_amount' => (float) ($item['TaxAmount'] ?? 0),
                            'cost_net' => $cost,
                        ]);
                        $stats['items']++;
                        if (! $variantId) {
                            $stats['items_unmatched']++;
                        }
                    }
                    $stats['orders']++;
                });
            }
        } catch (\Throwable $e) {
            $run->fail($e->getMessage(), $stats);
            throw $e;
        }

        arsort($platforms);
        $stats['unknown_platforms'] = $platforms;
        $message = $orderedSince ? 'Bestellungen der letzten '.self::FIRST_IMPORT_DAYS.' Tage' : 'Geänderte Bestellungen';
        if ($platforms) {
            $message .= '. Ohne Kanal: '.collect($platforms)->map(fn ($n, $p) => "{$p} ({$n})")->implode(', ').'. Unter Preisregeln bei „Plattform in Billbee“ eintragen.';
        }
        $run->finish($stats, $message);

        return $stats;
    }

    /** Ordnet bereits importierte Bestellungen neu zu, z. B. nachdem Plattformnamen oder SKUs ergänzt wurden. */
    public function rematch(): int
    {
        $channels = Channel::orderBy('position')->get();
        $changed = 0;
        foreach (Order::query()->cursor() as $order) {
            $channel = $channels->first(fn (Channel $c) => $c->matchesPlatform($order->platform));
            if ($order->channel_id !== $channel?->id) {
                $order->update(['channel_id' => $channel?->id]);
                $changed++;
            }
        }
        [$bySku, $byEan] = $this->variantLookup();
        foreach (OrderItem::whereNull('variant_id')->cursor() as $item) {
            $id = ($item->sku ? ($bySku[mb_strtolower($item->sku)] ?? null) : null) ?? ($item->ean ? ($byEan[$item->ean] ?? null) : null);
            if ($id) {
                $item->update(['variant_id' => $id, 'cost_net' => $item->cost_net ?? Variant::whereKey($id)->value('cost_net')]);
                $changed++;
            }
        }

        return $changed;
    }

    /** @return array{0: array<string, int>, 1: array<string, int>} eindeutige SKU- und EAN-Zuordnung */
    private function variantLookup(): array
    {
        $bySku = [];
        $byEan = [];
        $dupSku = [];
        foreach (Variant::query()->select(['id', 'sku', 'barcode'])->cursor() as $v) {
            if ($v->sku) {
                $k = mb_strtolower(trim($v->sku));
                isset($bySku[$k]) ? $dupSku[$k] = true : $bySku[$k] = $v->id;
            }
            if ($v->barcode) {
                $byEan[preg_replace('/\D/', '', $v->barcode)] ??= $v->id;
            }
        }
        foreach (array_keys($dupSku) as $k) {
            unset($bySku[$k]);
        }

        return [$bySku, $byEan];
    }
}
