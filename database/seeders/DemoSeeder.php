<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use App\Services\Shopify\ProductImporter;
use App\Services\Shopify\ShopifyClient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Beispieldaten für Entwicklung und Vorführung, ohne echten Shop.
 * php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(['email' => 'demo@marktschreier.test'], ['name' => 'Demo', 'password' => Hash::make('demo-passwort')]);

        $importer = new ProductImporter(new ShopifyClient('demo.myshopify.com', 'x', '2026-10', fn () => null));
        $items = [
            ['Deck Box 100+ Magnetverschluss', 'Johto Market', 'Deck Boxen', ['Schwarz', 'Blau', 'Rot'], 14.90, 'JM-DB100'],
            ['Sleeves Matt 100 Stück Standardgröße', 'Johto Market', 'Sleeves', ['Schwarz', 'Weiß', 'Grün', 'Lila'], 9.90, 'JM-SLV'],
            ['Spielmatte 60x35 cm genäht', 'Johto Market', 'Spielmatten', ['Ozean', 'Wald'], 24.90, 'JM-MAT'],
            ['Toploader 35pt 25 Stück', 'Ultra Pro', 'Schutzhüllen', [], 4.50, 'UP-TL35'],
            ['Binder 9-Pocket 360 Karten', 'Vault X', 'Ordner', ['Schwarz', 'Grau'], 29.90, 'VX-B9'],
            ['Inner Sleeves Perfect Fit 100 Stück', 'Dragon Shield', 'Sleeves', [], 7.90, 'DS-PF'],
            ['Würfelset 6W6 Acryl', 'Johto Market', 'Zubehör', ['Rot', 'Blau'], 6.90, 'JM-W6'],
            ['Magnetic Card Holder 35pt', 'Ultra Pro', 'Schutzhüllen', [], 3.20, 'UP-MH35'],
        ];
        foreach ($items as $i => [$title, $vendor, $type, $colors, $price, $sku]) {
            $variants = [];
            foreach ($colors ?: [null] as $j => $color) {
                $base = '42600'.str_pad((string) ($i * 10 + $j), 7, '0', STR_PAD_LEFT);
                $variants[] = [
                    'id' => 'gid://shopify/ProductVariant/'.($i * 100 + $j + 1),
                    'title' => $color ?? 'Default Title', 'sku' => $sku.($color ? '-'.mb_strtoupper(mb_substr($color, 0, 3)) : ''),
                    'price' => number_format($price, 2, '.', ''), 'compareAtPrice' => null, 'position' => $j + 1,
                    'barcodes' => ['nodes' => ($i === 6 && $j === 1) ? [] : [['value' => $base.$this->checkDigit($base)]]],
                    'selectedOptions' => $color ? [['name' => 'Farbe', 'value' => $color]] : [['name' => 'Title', 'value' => 'Default Title']],
                    'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/'.($i * 100 + $j + 1)],
                ];
            }
            $product = $importer->importNode([
                'id' => 'gid://shopify/Product/'.($i + 1), 'handle' => \Illuminate\Support\Str::slug($title), 'title' => $title,
                'descriptionHtml' => '<p>'.$title.' für Sammelkarten. Robust, passgenau und sofort lieferbar.</p>',
                'vendor' => $vendor, 'productType' => $type, 'tags' => ['TCG', $type], 'status' => $i === 7 ? 'DRAFT' : 'ACTIVE',
                'updatedAt' => now()->toIso8601String(), 'seo' => ['title' => null, 'description' => null],
                'options' => $colors ? [['name' => 'Farbe', 'position' => 1, 'optionValues' => array_map(fn ($c) => ['name' => $c], $colors)]] : [],
                'media' => ['nodes' => [['id' => 'gid://shopify/MediaImage/'.($i + 1), 'alt' => $title, 'image' => ['url' => 'https://placehold.co/600x600/0B6E69/FFFFFF/png?text='.urlencode($type)]]]],
                'variants' => ['nodes' => $variants],
            ]);
            foreach ($product->variants as $k => $v) {
                if ($i === 5) {
                    continue; // nicht in Billbee
                }
                $v->update(['billbee_id' => 1000 + $v->id, 'cost_net' => round($price * 0.38 + $k * 0.1, 2), 'stock' => ($i * 7 + $k * 13) % 60, 'billbee_synced_at' => now()]);
            }
        }

        $this->seedOrders();

        // Ein paar lokale Änderungen, damit Status und Diff sichtbar sind.
        $p = Product::where('title', 'like', 'Spielmatte%')->first();
        app(\App\Services\ProductEditor::class)->update($p, ['title' => 'Spielmatte 60x35 cm mit genähten Rändern', 'seo_title' => 'Spielmatte genäht kaufen'], [$p->variants[0]->id => ['price' => '22.90']]);
        $c = Product::where('title', 'like', 'Binder%')->first();
        $c->update(['push_status' => Product::STATUS_CONFLICT, 'push_error' => 'Dieselben Felder wurden hier und in Shopify geändert. Es gilt der Wert aus Marktschreier, bitte prüfen und übertragen.']);
    }

    /** Verkäufe der letzten 120 Tage, ohne Kundendaten. Deterministisch, damit Vorführungen gleich aussehen. */
    private function seedOrders(): void
    {
        mt_srand(42);
        $channels = \App\Models\Channel::pluck('id', 'key');
        // Welche Artikel wo laufen: Index in $items => [Kanal => Verkäufe pro Woche]
        $plan = [
            0 => ['shopify' => 3, 'amazon' => 6, 'ebay' => 2],
            1 => ['shopify' => 8, 'amazon' => 14],
            2 => ['shopify' => 2, 'ebay' => 1],
            3 => ['amazon' => 9, 'ebay' => 4],
            4 => ['shopify' => 1],
            6 => ['shopify' => 3, 'amazon' => 2],
            7 => ['ebay' => 1],
        ];
        $products = Product::with('variants')->orderBy('id')->get()->values();
        $id = 900000;
        for ($day = 120; $day >= 0; $day--) {
            foreach ($plan as $idx => $perChannel) {
                foreach ($perChannel as $key => $perWeek) {
                    if (mt_rand(1, 700) > $perWeek * 100) {
                        continue;
                    }
                    $variant = $products[$idx]->variants->random();
                    $qty = mt_rand(1, 10) > 8 ? 2 : 1;
                    $gross = (float) $variant->price * $qty;
                    $order = \App\Models\Order::create([
                        'billbee_id' => ++$id, 'order_number' => 'D'.$id, 'platform' => ucfirst($key), 'channel_id' => $channels[$key],
                        'state' => 4, 'ordered_at' => now()->subDays($day)->setTime(mt_rand(7, 22), mt_rand(0, 59)),
                        'total_gross' => $gross, 'shipping_gross' => 0, 'currency' => 'EUR',
                    ]);
                    \App\Models\OrderItem::create([
                        'order_id' => $order->id, 'variant_id' => $variant->id, 'sku' => $variant->sku, 'ean' => $variant->barcode, 'title' => $products[$idx]->title,
                        'quantity' => $qty, 'total_gross' => $gross, 'tax_amount' => round($gross - $gross / 1.19, 2), 'cost_net' => $variant->fresh()->cost_net,
                    ]);
                }
            }
        }
        \App\Models\SyncRun::create(['type' => \App\Models\SyncRun::TYPE_BILLBEE_ORDERS, 'status' => 'ok', 'stats' => ['orders' => $id - 900000], 'message' => 'Beispieldaten', 'started_at' => now(), 'finished_at' => now()]);
    }

    private function checkDigit(string $twelve): int
    {
        $sum = 0;
        foreach (str_split($twelve) as $i => $d) {
            $sum += (int) $d * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10;
    }
}
