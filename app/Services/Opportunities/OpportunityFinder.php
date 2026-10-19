<?php

namespace App\Services\Opportunities;

use App\Models\Channel;
use App\Models\OpportunityDismissal;
use App\Models\Order;
use App\Models\Product;
use App\Models\Variant;
use App\Services\Pricing\PriceCalculator;
use App\Services\Sales\SalesStats;
use App\Support\Format;
use Illuminate\Support\Collection;

/**
 * Chancenfinder: regelbasiert, nachvollziehbar, ohne KI.
 * Kostenlose Chancen (Listing, Daten, Preis, Bestand) und Werbe-Chancen (wo sich Anzeigen rechnen).
 * Alle Euro-Werte sind grobe Schätzungen auf Basis der letzten 90 Tage.
 */
class OpportunityFinder
{
    /** Schwellen, bewusst an einer Stelle */
    public const MIN_UNITS_FOR_CHANNEL_HINT = 3;     // Stück in 90 Tagen
    public const CHANNEL_SHARE_GUESS = 0.3;          // so viel zusätzlich trauen wir einem neuen Kanal zu
    public const MIN_PROFIT_PER_SALE = 1.0;          // darunter: zu wenig Gewinn
    public const STOCK_WARNING_DAYS = 14;
    public const SLOW_MOVER_MIN_CAPITAL = 50.0;      // EK × Bestand
    public const AD_MIN_UNITS = 5;                   // Stück in 90 Tagen
    public const AD_MIN_PROFIT = 2.0;                // Gewinn je Verkauf
    public const AD_MIN_STOCK_DAYS = 30;
    public const AD_UPLIFT_GUESS = 0.3;              // erwartetes Plus an Verkäufen durch Werbung
    public const AD_TARGET_SHARE = 0.6;              // Ziel-ACoS = 60 % des Break-even-ACoS

    private ?array $cache = null;

    public function __construct(private SalesStats $stats, private PriceCalculator $calc)
    {
    }

    /** @return Collection<int, Opportunity> sortiert, ohne ausgeblendete */
    public function all(bool $includeDismissed = false): Collection
    {
        $this->cache ??= $this->compute();
        $dismissed = $includeDismissed ? [] : OpportunityDismissal::activeKeys();

        return collect($this->cache)
            ->reject(fn (Opportunity $o) => isset($dismissed[$o->key()]))
            ->sortByDesc(fn (Opportunity $o) => $o->sortValue())
            ->values();
    }

    /** @return list<Opportunity> */
    private function compute(): array
    {
        $hasOrders = Order::exists();
        $s90 = $this->stats->period(90);
        $s30 = $this->stats->period(30);
        $channels = Channel::active()->get();
        $shop = $channels->firstWhere('key', Channel::SHOPIFY);
        $marketplaces = $channels->reject->isShopify();

        // Kanäle, deren Angebote wir tatsächlich kennen (Marktplatz verbunden und gelesen).
        $knownListings = [];
        foreach ($marketplaces as $mpChannel) {
            $conn = \App\Models\MarketplaceConnection::where('provider', $mpChannel->key)->first();
            if ($conn?->listings_checked_at) {
                $knownListings[$mpChannel->id] = true;
            }
        }

        $amazonChannel = $marketplaces->firstWhere('key', 'amazon');
        $products = Product::with(['variants.channelPrices', 'variants.listings', 'images:id,product_id', 'channelContents'])->withCount('attributeValues')->get();
        $out = [];

        foreach ($products as $product) {
            $variants = $product->variants;
            $units90 = $variants->sum(fn ($v) => $s90['variants'][$v->id]['quantity'] ?? 0);
            $profit90 = $variants->sum(fn ($v) => $s90['variants'][$v->id]['profit'] ?? 0);
            $stock = $variants->whereNotNull('stock')->sum('stock');
            $knownStock = $variants->whereNotNull('stock')->isNotEmpty();
            $url = route('products.edit', $product);

            // Einheiten je Kanal (Produkt gesamt)
            $byChannel = [];
            foreach ($variants as $v) {
                foreach ($s90['variants'][$v->id]['by_channel'] ?? [] as $cid => $q) {
                    $byChannel[$cid] = ($byChannel[$cid] ?? 0) + $q;
                }
            }
            $main = $variants->sortByDesc(fn ($v) => $s90['variants'][$v->id]['quantity'] ?? 0)->first();
            $mainCost = $main?->cost_net !== null ? (float) $main->cost_net : null;

            // 1. Fehlt auf einem Marktplatz
            foreach ($marketplaces as $channel) {
                $listed = $variants->contains(fn ($v) => $v->listings->contains(fn ($l) => $l->channel_id === $channel->id && in_array($l->status, ['active', 'pending'], true)));
                $errors = $variants->flatMap(fn ($v) => $v->listings->where('channel_id', $channel->id)->where('status', 'error'));
                if ($errors->isNotEmpty()) {
                    $out[] = new Opportunity(
                        type: 'listing_error', kind: Opportunity::FREE,
                        title: "{$product->title}: Problem mit dem Angebot auf {$channel->name}",
                        text: \Illuminate\Support\Str::limit((string) $errors->first()->last_error, 220),
                        product: $product, channel: $channel, weight: 30 + $units90,
                        actionLabel: 'Marktplätze ansehen', actionUrl: $url.'?tab=marktplaetze',
                    );
                }
                $known = isset($knownListings[$channel->id]);
                $soldThere = ($byChannel[$channel->id] ?? 0) > 0;
                if ($listed || $soldThere || $product->status !== 'ACTIVE' || ! $hasOrders || $units90 < self::MIN_UNITS_FOR_CHANNEL_HINT) {
                    continue;
                }
                $hasOwnPrice = $variants->contains(fn ($v) => $v->channelPrices->contains('channel_id', $channel->id));
                $profitThere = $main ? $this->calc->profit($main->priceOn($channel), $mainCost, $channel) : null;
                $monthly = $profitThere !== null && $profitThere > 0 ? $units90 / 3 * self::CHANNEL_SHARE_GUESS * $profitThere : null;
                $missingGtin = $variants->contains(fn ($v) => blank($v->barcode));
                $sold = collect($byChannel)->map(fn ($q, $cid) => ($channels->firstWhere('id', $cid)?->name ?? 'anderen Kanälen').' '.$this->qty($q))->implode(', ');
                $out[] = new Opportunity(
                    type: 'missing_channel', kind: Opportunity::FREE,
                    title: "{$product->title} auf {$channel->name} anbieten",
                    text: "Verkauft sich in 90 Tagen über {$sold}"
                        .($known ? ", auf {$channel->name} gibt es kein aktives Angebot." : ", auf {$channel->name} gab es keinen Verkauf.".($hasOwnPrice ? ' Ein Preis für den Kanal ist hinterlegt, Listing prüfen.' : ' Falls der Artikel dort noch nicht gelistet ist: einstellen.'))
                        .($missingGtin ? ' Vorher GTIN ergänzen, Marktplätze verlangen sie.' : ''),
                    product: $product, channel: $channel, monthlyValue: $monthly, weight: $units90,
                    actionLabel: $known ? 'Bei '.$channel->name.' anbieten' : 'Preis für '.$channel->name.' prüfen',
                    actionUrl: $url.'?tab='.($known ? 'marktplaetze' : 'preise'),
                    facts: array_filter(['Gewinn je Verkauf dort' => $profitThere !== null ? Format::money($profitThere) : null]),
                );
            }

            // 2. Im Shop nicht aktiv, verkauft sich aber anderswo oder liegt auf Lager
            if ($shop && $product->status !== 'ACTIVE' && ($units90 > 0 || ($knownStock && $stock > 0))) {
                $out[] = new Opportunity(
                    type: 'shop_inactive', kind: Opportunity::FREE,
                    title: "{$product->title} ist im Onlineshop nicht sichtbar",
                    text: 'Status in Shopify: '.Format::shopifyStatus($product->status).'.'
                        .($units90 > 0 ? ' In 90 Tagen trotzdem '.$this->qty($units90).' über andere Kanäle verkauft.' : '')
                        .($knownStock && $stock > 0 ? " {$stock} Stück auf Lager." : ''),
                    product: $product, monthlyValue: $units90 > 0 && $profit90 > 0 ? $profit90 / 3 * self::CHANNEL_SHARE_GUESS : null, weight: $units90 + $stock / 10,
                    actionLabel: 'Status ändern', actionUrl: $url,
                );
            }

            // 3. Daten unvollständig (bremst Marktplätze und Suchmaschinen)
            $gaps = [];
            $noGtin = $variants->filter(fn ($v) => blank($v->barcode))->count();
            if ($noGtin) {
                $gaps[] = $noGtin === $variants->count() ? 'keine GTIN' : "{$noGtin} von {$variants->count()} Varianten ohne GTIN";
            }
            $descLen = mb_strlen(trim(strip_tags((string) $product->description_html)));
            if ($descLen < 120) {
                $gaps[] = $descLen === 0 ? 'keine Beschreibung' : "Beschreibung sehr kurz ({$descLen} Zeichen, gut sind ab 120)";
            }
            if ($product->images->isEmpty()) {
                $gaps[] = 'kein Bild';
            }
            if (blank($product->seo_description)) {
                $gaps[] = 'keine SEO-Beschreibung';
            }
            if ($product->attribute_values_count === 0 && $marketplaces->isNotEmpty()) {
                $gaps[] = 'keine Merkmale für Marktplätze';
            }
            if ($amazonChannel && $variants->contains(fn ($v) => $v->listings->contains(fn ($l) => $l->channel_id === $amazonChannel->id && $l->status === 'active'))
                && empty($product->channelContents->firstWhere('channel_id', $amazonChannel->id)?->bullets)) {
                $gaps[] = 'keine Amazon-Stichpunkte';
            }
            if ($gaps && ($units90 > 0 || ($knownStock && $stock > 0))) {
                $out[] = new Opportunity(
                    type: 'incomplete', kind: Opportunity::FREE,
                    title: "{$product->title}: Daten ergänzen",
                    text: ucfirst(implode(', ', $gaps)).'.',
                    product: $product, weight: 5 + $units90 + count($gaps),
                    actionLabel: 'Artikel bearbeiten', actionUrl: $url,
                );
            }

            foreach ($variants as $v) {
                $vs90 = $s90['variants'][$v->id] ?? null;
                $vs30 = $s30['variants'][$v->id] ?? null;
                $cost = $v->cost_net !== null ? (float) $v->cost_net : null;
                $name = $product->title.($v->label() !== 'Standard' ? ' ('.$v->label().')' : '');

                // 4. Zu wenig Gewinn je Verkauf auf einem Kanal, auf dem verkauft wird
                if ($vs90 && $cost !== null) {
                    foreach ($vs90['by_channel'] as $cid => $q) {
                        $channel = $channels->firstWhere('id', $cid);
                        if (! $channel) {
                            continue;
                        }
                        $price = $v->priceOn($channel);
                        $profit = $this->calc->profit($price, $cost, $channel);
                        $minProfit = (float) \App\Models\Setting::get('opportunities.min_profit', self::MIN_PROFIT_PER_SALE);
                        if ($profit !== null && $profit < $minProfit) {
                            $monthlyUnits = $q / 3;
                            $out[] = new Opportunity(
                                type: 'low_profit', kind: Opportunity::FREE,
                                title: "{$name}: ".($profit < 0 ? 'Verlust' : 'kaum Gewinn')." auf {$channel->name}",
                                text: 'Bei '.Format::money($price).' bleiben nach EK, Gebühren und Versand '.Format::money($profit).' je Verkauf. In 90 Tagen '.$this->qty($q).' verkauft. Preis mit einem Regelwerk neu rechnen oder Kosten prüfen.',
                                product: $product, variant: $v, channel: $channel,
                                monthlyValue: $monthlyUnits * ($minProfit - $profit),
                                actionLabel: 'Preise je Kanal', actionUrl: $url.'?tab=preise',
                                facts: ['Preis' => Format::money($price), 'Gewinn je Verkauf' => Format::money($profit)],
                            );
                        }
                    }
                }

                // 5. Bald ausverkauft
                $rate = ($vs30['quantity'] ?? 0) / 30;
                if ($rate > 0 && $v->stock !== null) {
                    $daysLeft = $v->stock / $rate;
                    if ($daysLeft < self::STOCK_WARNING_DAYS) {
                        $unitProfit = $vs30 && $vs30['quantity'] > 0 && $vs30['items_without_cost'] === 0 ? $vs30['profit'] / $vs30['quantity'] : null;
                        $out[] = new Opportunity(
                            type: 'stockout', kind: Opportunity::FREE,
                            title: $v->stock <= 0 ? "{$name} ist ausverkauft" : "{$name} reicht noch etwa ".max(1, (int) floor($daysLeft)).' Tage',
                            text: 'Verkauft '.number_format($rate * 7, 1, ',', '.').' Stück pro Woche, Bestand laut Billbee: '.$v->stock.'. Nachbestellen, bevor Verkäufe und Ranking verloren gehen.',
                            product: $product, variant: $v,
                            monthlyValue: $unitProfit !== null && $unitProfit > 0 ? $rate * 30 * $unitProfit : null,
                            weight: 20 + $rate * 30,
                            actionLabel: 'Artikel öffnen', actionUrl: $url.'?tab=varianten',
                            facts: ['Bestand' => (string) $v->stock, 'Verkauf je Woche' => number_format($rate * 7, 1, ',', '.')],
                        );
                    }
                }

                // 6. Liegt auf Lager ohne Verkauf
                if ($hasOrders && ! $vs90 && $v->stock > 0 && $cost !== null && $cost * $v->stock >= self::SLOW_MOVER_MIN_CAPITAL) {
                    $capital = $cost * $v->stock;
                    $out[] = new Opportunity(
                        type: 'slow_mover', kind: Opportunity::FREE,
                        title: "{$name}: 90 Tage ohne Verkauf",
                        text: "{$v->stock} Stück binden ".Format::money($capital, 0).' im Einkauf. Sichtbarkeit prüfen, als Bundle anbieten oder den Preis senken.',
                        product: $product, variant: $v, weight: $capital / 10,
                        actionLabel: 'Preise je Kanal', actionUrl: $url.'?tab=preise',
                        facts: ['Kapital' => Format::money($capital, 0)],
                    );
                }
            }

            // 7. Werbekandidat: verkauft sich, verdient genug, Bestand reicht
            if ($units90 >= self::AD_MIN_UNITS && $main && $mainCost !== null) {
                $rate30 = $variants->sum(fn ($v) => $s30['variants'][$v->id]['quantity'] ?? 0) / 30;
                $stockDays = $knownStock && $rate30 > 0 ? $stock / $rate30 : ($knownStock && $stock > 0 ? 999 : 0);
                if ($stockDays >= self::AD_MIN_STOCK_DAYS) {
                    // Werbung dort, wo der Artikel schon am meisten Gewinn bringt (Stück × Gewinn je Verkauf).
                    $best = null;
                    foreach ($channels as $channel) {
                        $price = $main->priceOn($channel);
                        $profit = $this->calc->profit($price, $mainCost, $channel);
                        if ($profit === null || $profit < self::AD_MIN_PROFIT) {
                            continue;
                        }
                        $score = (($byChannel[$channel->id] ?? 0) + 0.1) * $profit;
                        if (! $best || $score > $best['score']) {
                            $best = compact('channel', 'price', 'profit', 'score');
                        }
                    }
                    if ($best) {
                        $breakEven = $best['profit'] / $best['price'];
                        $target = $breakEven * self::AD_TARGET_SHARE;
                        $extraUnits = $units90 / 3 * self::AD_UPLIFT_GUESS;
                        $monthly = $extraUnits * ($best['profit'] - $best['price'] * $target);
                        $where = $best['channel']->key === 'amazon' ? 'Sponsored Products auf Amazon'
                            : ($best['channel']->isShopify() ? 'Google Shopping oder Meta für den Onlineshop' : 'Anzeigen auf '.$best['channel']->name);
                        $out[] = new Opportunity(
                            type: 'ad_candidate', kind: Opportunity::ADS,
                            title: "{$product->title}: {$where}",
                            text: 'Verkauft sich ohne Werbung ('.$this->qty($units90).' in 90 Tagen), bringt '.Format::money($best['profit']).' Gewinn je Verkauf auf '.$best['channel']->name
                                .' und der Bestand reicht.'.' Bis '.Format::percent($breakEven * 100).' Werbekosten vom Umsatz bleibt es ohne Verlust, Ziel: höchstens '.Format::percent($target * 100).'.',
                            product: $product, channel: $best['channel'], monthlyValue: $monthly, weight: $units90,
                            actionLabel: $best['channel']->key === 'amazon' ? 'Kampagne anlegen' : 'Artikel öffnen',
                            actionUrl: $best['channel']->key === 'amazon' ? '/werbung?neu='.$product->id : $url,
                            facts: [
                                'Werbekosten-Grenze' => Format::percent($breakEven * 100),
                                'Ziel-ACoS' => Format::percent($target * 100),
                                'Startbudget' => Format::money(max(5, min(20, round($best['price'] * $target * $extraUnits / 30, 0))), 0).' am Tag',
                            ],
                        );
                    }
                }
            }
        }

        return $out;
    }

    private function qty(float $q): string
    {
        $n = (int) round($q);

        return number_format($n, 0, ',', '.').' Stück';
    }
}
