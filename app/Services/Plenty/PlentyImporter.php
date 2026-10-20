<?php

namespace App\Services\Plenty;

use App\Models\Channel;
use App\Models\ChangeLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Setting;
use App\Models\SyncRun;
use App\Models\Variant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PlentyONE andocken: Artikel, Varianten, Preise, EK, Bestand und Aufträge lesen.
 * plenty bleibt führend bis zum Umzug. Lokal geänderte Felder werden beim Import nicht überschrieben.
 */
class PlentyImporter
{
    public const TYPE_ITEMS = 'plenty_items';
    public const TYPE_ORDERS = 'plenty_orders';

    public function __construct(private PlentyClient $client)
    {
    }

    public function salesPriceId(): int
    {
        return (int) Setting::get('plenty.sales_price_id', 1);
    }

    /** @return list<int> leer = alle Lager */
    public function warehouseIds(): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) Setting::get('plenty.warehouse_ids', '')))));
    }

    public function importItems(bool $full = false, ?SyncRun $run = null): array
    {
        $run ??= SyncRun::start(self::TYPE_ITEMS);
        $stats = ['products_new' => 0, 'products_updated' => 0, 'variants' => 0, 'conflicts' => 0];
        $last = SyncRun::lastSuccessful(self::TYPE_ITEMS);
        $since = $full || ! $last ? null : $last->started_at->copy()->subMinutes(10);
        $lang = (string) Setting::get('plenty.lang', 'de');

        try {
            $manufacturers = collect(iterator_to_array($this->client->paginate('items/manufacturers', [], 250), false))->pluck('name', 'id');

            $itemQuery = ['lang' => $lang, 'with' => 'itemImages'];
            if ($since) {
                $itemQuery['updatedBetween'] = $since->timestamp.','.now()->timestamp;
            }
            foreach ($this->client->paginate('items', $itemQuery, 50) as $item) {
                $this->importItem($item, $manufacturers->all(), $lang, $stats);
            }

            $varQuery = ['with' => 'variationSalesPrices,variationBarcodes,variationAttributeValues,stock'];
            if ($since) {
                $varQuery['relatedUpdatedBetween'] = $since->timestamp.','.now()->timestamp;
            }
            foreach ($this->client->paginate('items/variations', $varQuery, 100) as $variation) {
                $this->importVariation($variation, $stats);
            }
        } catch (\Throwable $e) {
            $run->fail($e->getMessage(), $stats);
            throw $e;
        }
        $run->finish($stats, $since ? 'Änderungen seit '.$since->timezone('Europe/Berlin')->format('d.m.Y H:i') : 'Vollständiger Import aus PlentyONE');

        return $stats;
    }

    private function importItem(array $item, array $manufacturers, string $lang, array &$stats): void
    {
        $text = collect($item['texts'] ?? [])->firstWhere('lang', $lang) ?? ($item['texts'][0] ?? []);
        DB::transaction(function () use ($item, $manufacturers, $text, &$stats) {
            $product = Product::firstOrNew(['plenty_id' => $item['id']]);
            $isNew = ! $product->exists;
            $remote = [
                'title' => trim((string) ($text['name1'] ?? '')) ?: 'Artikel '.$item['id'],
                'description_html' => $text['description'] ?? null,
                'vendor' => $manufacturers[$item['manufacturerId'] ?? 0] ?? null,
                'seo_title' => ($text['title'] ?? '') ?: null,
                'seo_description' => ($text['metaDescription'] ?? '') ?: null,
                'tags' => collect(explode(',', (string) ($text['keywords'] ?? '')))->map(fn ($t) => trim($t))->filter()->unique()->take(30)->values()->all(),
            ];
            $product->status ??= 'ACTIVE';
            $conflict = $this->merge($product, $remote, $isNew);
            $product->handle = $text['urlPath'] ?? $product->handle;
            $product->push_status ??= Product::STATUS_SYNCED;
            if ($conflict) {
                $product->push_status = Product::STATUS_CONFLICT;
                $product->push_error = 'Dieselben Felder wurden hier und in PlentyONE geändert. Es gilt der Wert aus Marktschreier.';
                $stats['conflicts']++;
            }
            $product->save();

            $product->images()->delete();
            foreach (collect($item['itemImages'] ?? [])->sortBy('position')->values() as $i => $img) {
                if (! empty($img['url'])) {
                    ProductImage::create(['product_id' => $product->id, 'url' => $img['url'], 'alt' => $img['names'][0]['alternate'] ?? null, 'position' => $i + 1]);
                }
            }
            $stats[$isNew ? 'products_new' : 'products_updated']++;
        });
    }

    private function importVariation(array $v, array &$stats): void
    {
        $product = Product::where('plenty_id', $v['itemId'])->first();
        if (! $product) {
            return; // Artikel kommt beim nächsten vollständigen Import
        }
        $price = collect($v['variationSalesPrices'] ?? [])->firstWhere('salesPriceId', $this->salesPriceId())['price'] ?? null;
        $warehouses = $this->warehouseIds();
        $stock = collect($v['stock'] ?? [])->filter(fn ($s) => $warehouses === [] || in_array((int) $s['warehouseId'], $warehouses, true))->sum('netStock');
        $attrs = collect($v['variationAttributeValues'] ?? [])->map(fn ($a) => $a['attributeValue']['backendName'] ?? $a['valueName'] ?? null)->filter()->values();

        DB::transaction(function () use ($product, $v, $price, $stock, $attrs, &$stats) {
            $variant = Variant::firstOrNew(['plenty_variation_id' => $v['id']]);
            $isNew = ! $variant->exists;
            $variant->product_id = $product->id;
            $variant->setRelation('product', $product);
            $remote = [
                'sku' => (string) ($v['number'] ?? '') ?: null,
                'barcode' => $v['variationBarcodes'][0]['code'] ?? null,
                'price' => $price,
            ];
            if ($this->merge($variant, $remote, $isNew)) {
                $product->update(['push_status' => Product::STATUS_CONFLICT]);
                $stats['conflicts']++;
            }
            $variant->fill([
                'title' => $attrs->implode(' / ') ?: ($v['name'] ?? null),
                'option1' => $attrs[0] ?? null, 'option2' => $attrs[1] ?? null, 'option3' => $attrs[2] ?? null,
                'position' => (int) ($v['position'] ?? 0),
                'cost_net' => isset($v['purchasePrice']) ? (float) $v['purchasePrice'] : $variant->cost_net,
                'stock' => (int) floor($stock),
            ])->save();
            if (($v['isMain'] ?? false) && ! $product->isLocallyChanged('status')) {
                // Aktivität der Hauptvariante bestimmt, ob der Artikel sichtbar ist
                $status = ($v['isActive'] ?? true) ? 'ACTIVE' : 'DRAFT';
                $product->status = $status;
                $product->synced_snapshot = array_replace($product->synced_snapshot ?? [], ['status' => $status]);
                $product->save();
            }
            $stats['variants']++;
        });
    }

    /** Übernimmt nur Felder, die lokal nicht geändert wurden. Liefert true bei Konflikt. */
    private function merge(Product|Variant $model, array $remote, bool $isNew): bool
    {
        $class = $model::class;
        $snapshot = $model->synced_snapshot ?? [];
        $conflict = false;
        foreach ($remote as $field => $value) {
            if ($isNew) {
                $model->setAttribute($field, $value);

                continue;
            }
            $remoteNorm = $class::normalizeValue($field, $value);
            $remoteChanged = $class::normalizeValue($field, $snapshot[$field] ?? null) !== $remoteNorm;
            if (! $model->isLocallyChanged($field)) {
                if ($remoteChanged) {
                    $product = $model instanceof Product ? $model : $model->product;
                    ChangeLog::record($product, $field, $model->getAttribute($field), $value, 'import', $model instanceof Variant ? $model : null);
                }
                $model->setAttribute($field, $value);
            } elseif ($remoteChanged && $class::normalizeValue($field, $model->getAttribute($field)) !== $remoteNorm) {
                $conflict = true;
            }
        }
        // Basis für den nächsten Vergleich: alle vom Schnittstellenpartner gelieferten Felder
        $base = [];
        foreach ($class::pushableFields() as $f) {
            $base[$f] = array_key_exists($f, $remote) ? $class::normalizeValue($f, $remote[$f]) : $class::normalizeValue($f, $model->getAttribute($f));
        }
        $model->synced_snapshot = $base;

        return $conflict;
    }

    /** Aufträge aus PlentyONE für Umsatz, Gewinn und Chancen. Ohne Kundendaten. */
    public function importOrders(?SyncRun $run = null): array
    {
        $run ??= SyncRun::start(self::TYPE_ORDERS);
        $stats = ['orders' => 0, 'items' => 0, 'items_unmatched' => 0, 'orders_without_channel' => 0];
        $last = SyncRun::lastSuccessful(self::TYPE_ORDERS);
        $query = ['orderTypes' => 1];
        $query[$last ? 'updatedAtFrom' : 'createdAtFrom'] = ($last ? $last->started_at->copy()->subMinutes(10) : now()->subDays(365))->toIso8601String();

        try {
            $referrers = collect($this->client->get('orders/referrers'))->mapWithKeys(fn ($r) => [(string) $r['id'] => $r['name'] ?? $r['backendName'] ?? '']);
            $channels = Channel::orderBy('position')->get();
            $variants = Variant::whereNotNull('plenty_variation_id')->pluck('id', 'plenty_variation_id');

            foreach ($this->client->paginate('orders', $query, 100) as $o) {
                if ((int) ($o['typeId'] ?? 1) !== 1) {
                    continue;
                }
                $refName = (string) ($referrers[(string) $o['referrerId']] ?? '');
                // Referrer 1 ist der eigene Shop (Mandant)
                $channel = (int) floor((float) $o['referrerId']) === 1
                    ? $channels->firstWhere('key', 'shopify')
                    : $channels->first(fn (Channel $c) => $c->matchesPlatform($refName));
                if (! $channel) {
                    $stats['orders_without_channel']++;
                }
                $status = (float) ($o['statusId'] ?? 0);
                $amount = collect($o['amounts'] ?? [])->firstWhere('isSystemCurrency', true) ?? ($o['amounts'][0] ?? []);
                $created = collect($o['dates'] ?? [])->firstWhere('typeId', 2)['date'] ?? $o['createdAt'] ?? null;

                DB::transaction(function () use ($o, $refName, $channel, $status, $amount, $created, $variants, &$stats) {
                    $order = Order::updateOrCreate(['plenty_id' => $o['id']], [
                        'order_number' => (string) $o['id'],
                        'platform' => $refName ?: 'Herkunft '.$o['referrerId'],
                        'channel_id' => $channel?->id,
                        // storniert (8.x) zählt nicht als Verkauf
                        'state' => $status >= 8 && $status < 9 ? 8 : 4,
                        'ordered_at' => $created ? Carbon::parse($created) : null,
                        'total_gross' => (float) ($amount['grossTotal'] ?? 0),
                        'shipping_gross' => (float) collect($o['orderItems'] ?? [])->where('typeId', 6)->sum(fn ($i) => $i['amounts'][0]['priceGross'] ?? 0),
                        'currency' => $amount['currency'] ?? 'EUR',
                    ]);
                    $previousCosts = $order->items()->pluck('cost_net', 'variant_id')->all();
                    $order->items()->delete();
                    foreach ($o['orderItems'] ?? [] as $item) {
                        if (! in_array((int) $item['typeId'], [1, 2, 3], true)) {
                            continue; // Versand, Gutscheine, Zahlungsaufschläge
                        }
                        $variantId = $variants[$item['itemVariationId']] ?? null;
                        $qty = (float) $item['quantity'];
                        $a = $item['amounts'][0] ?? [];
                        $gross = (float) ($a['priceGross'] ?? 0) * $qty;
                        $net = (float) ($a['priceNet'] ?? 0) * $qty;
                        OrderItem::create([
                            'order_id' => $order->id, 'variant_id' => $variantId,
                            'title' => mb_substr((string) ($item['orderItemName'] ?? ''), 0, 255) ?: null,
                            'quantity' => $qty, 'total_gross' => $gross, 'tax_amount' => round($gross - $net, 2),
                            'cost_net' => $previousCosts[$variantId] ?? ($variantId ? Variant::whereKey($variantId)->value('cost_net') : null),
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
        $run->finish($stats, $last ? 'Geänderte Aufträge' : 'Aufträge der letzten 365 Tage');

        return $stats;
    }
}
