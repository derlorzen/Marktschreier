<?php

namespace App\Services\Marketplaces\Ebay;

use App\Models\Channel;
use App\Models\Listing;
use App\Models\MarketplaceConnection;
use App\Models\Product;
use App\Models\Variant;
use App\Services\Marketplaces\MarketplaceException;
use Illuminate\Support\Collection;
use SimpleXMLElement;

class EbayService
{
    public const CATEGORY_TREE_DE = '77';

    public const CONDITIONS = [1000 => 'Neu', 1500 => 'Neu: Sonstige (siehe Artikelbeschreibung)', 3000 => 'Gebraucht'];

    public function __construct(private EbayClient $client)
    {
    }

    public function client(): EbayClient
    {
        return $this->client;
    }

    public function channel(): Channel
    {
        return Channel::where('key', 'ebay')->firstOrFail();
    }

    /** Nach dem Verbinden: eBay-Nutzername merken. */
    public function refreshAccountName(): string
    {
        $doc = $this->client->trading('GetUser', '');
        $name = (string) $doc->User->UserID;
        $this->client->connection()->update(['account_name' => $name ?: null]);

        return $name;
    }

    /** @return array{fulfillment: array<string,string>, payment: array<string,string>, return: array<string,string>} */
    public function policies(): array
    {
        $mp = ['marketplace_id' => config('marktschreier.ebay.marketplace_id')];
        $map = fn (array $items, string $idKey) => collect($items)->mapWithKeys(fn ($p) => [$p[$idKey] => $p['name'] ?? $p[$idKey]])->all();

        return [
            'fulfillment' => $map($this->client->rest('GET', '/sell/account/v1/fulfillment_policy', $mp)['fulfillmentPolicies'] ?? [], 'fulfillmentPolicyId'),
            'payment' => $map($this->client->rest('GET', '/sell/account/v1/payment_policy', $mp)['paymentPolicies'] ?? [], 'paymentPolicyId'),
            'return' => $map($this->client->rest('GET', '/sell/account/v1/return_policy', $mp)['returnPolicies'] ?? [], 'returnPolicyId'),
        ];
    }

    /**
     * Liest alle aktiven Angebote und ordnet sie über die SKU den Varianten zu.
     *
     * @return array{listings: int, matched: int, unmatched: int}
     */
    public function syncListings(): array
    {
        $channel = $this->channel();
        $bySku = Variant::whereNotNull('sku')->pluck('id', 'sku')->mapWithKeys(fn ($id, $sku) => [mb_strtolower(trim($sku)) => $id]);
        $seen = [];
        $stats = ['listings' => 0, 'matched' => 0, 'unmatched' => 0];
        $page = 1;

        do {
            $doc = $this->client->trading('GetMyeBaySelling', '<ActiveList><Include>true</Include><IncludeNotes>false</IncludeNotes>'
                .'<Pagination><EntriesPerPage>200</EntriesPerPage><PageNumber>'.$page.'</PageNumber></Pagination></ActiveList>'
                .'<DetailLevel>ReturnAll</DetailLevel>');
            $list = $doc->ActiveList;
            $pages = (int) ($list->PaginationResult->TotalNumberOfPages ?? 1);
            foreach ($list->ItemArray->Item ?? [] as $item) {
                $stats['listings']++;
                $itemId = (string) $item->ItemID;
                $rows = [];
                if (isset($item->Variations->Variation)) {
                    foreach ($item->Variations->Variation as $var) {
                        $rows[] = ['sku' => (string) $var->SKU, 'price' => (float) $var->StartPrice, 'variation' => true];
                    }
                } else {
                    $rows[] = ['sku' => (string) $item->SKU, 'price' => (float) ($item->SellingStatus->CurrentPrice ?? $item->BuyItNowPrice ?? 0), 'variation' => false];
                }
                foreach ($rows as $row) {
                    $variantId = $row['sku'] !== '' ? ($bySku[mb_strtolower(trim($row['sku']))] ?? null) : null;
                    if (! $variantId) {
                        $stats['unmatched']++;

                        continue;
                    }
                    $listing = Listing::firstOrNew(['variant_id' => $variantId, 'channel_id' => $channel->id]);
                    $listing->fill([
                        'external_id' => $itemId, 'sku' => $row['sku'], 'status' => 'active', 'remote_price' => $row['price'],
                        'last_error' => null, 'checked_at' => now(),
                        'data' => array_replace($listing->data ?? [], ['variation' => $row['variation'], 'title' => (string) $item->Title]),
                    ]);
                    $listing->adoptRemotePrice();
                    $listing->save();
                    $seen[] = $listing->id;
                    $stats['matched']++;
                }
            }
            $page++;
        } while ($page <= $pages);

        // Nicht mehr aktive Angebote als inaktiv markieren, Fehler und laufende Anlagen in Ruhe lassen.
        Listing::where('channel_id', $channel->id)->whereNotIn('id', $seen)->where('status', 'active')
            ->update(['status' => 'inactive', 'checked_at' => now()]);
        $this->client->connection()->update(['listings_checked_at' => now()]);

        return $stats;
    }

    /**
     * Überträgt nur den Preis. Die Menge bleibt unangetastet, die pflegt Billbee.
     *
     * @param  Collection<int, Listing>  $listings
     * @return array{ok: int, failed: int}
     */
    public function pushPrices(Collection $listings): array
    {
        $result = ['ok' => 0, 'failed' => 0];
        foreach ($listings->filter(fn (Listing $l) => $l->external_id)->chunk(4) as $chunk) {
            $inner = '';
            foreach ($chunk as $l) {
                $price = $l->variant->priceOn($l->channel);
                $inner .= '<InventoryStatus><ItemID>'.EbayClient::x($l->external_id).'</ItemID>'
                    .(($l->data['variation'] ?? false) && $l->sku ? '<SKU>'.EbayClient::x($l->sku).'</SKU>' : '')
                    .'<StartPrice>'.number_format($price, 2, '.', '').'</StartPrice></InventoryStatus>';
            }
            try {
                $this->client->trading('ReviseInventoryStatus', $inner);
                foreach ($chunk as $l) {
                    $price = $l->variant->priceOn($l->channel);
                    $l->update(['remote_price' => $price, 'pushed_price' => $price, 'price_pushed_at' => now(), 'last_error' => null, 'status' => $l->status === 'error' ? 'active' : $l->status]);
                    $result['ok']++;
                }
            } catch (MarketplaceException $e) {
                foreach ($chunk as $l) {
                    $l->update(['last_error' => $e->getMessage()]);
                    $result['failed']++;
                }
            }
        }

        return $result;
    }

    /** @return list<array{id: string, name: string, path: string}> */
    public function suggestCategories(string $query): array
    {
        $json = $this->client->rest('GET', '/commerce/taxonomy/v1/category_tree/'.self::CATEGORY_TREE_DE.'/get_category_suggestions', ['q' => mb_substr($query, 0, 100)], appToken: true);

        return collect($json['categorySuggestions'] ?? [])->take(10)->map(fn ($s) => [
            'id' => (string) $s['category']['categoryId'],
            'name' => $s['category']['categoryName'],
            'path' => collect($s['categoryTreeNodeAncestors'] ?? [])->reverse()->pluck('categoryName')->push($s['category']['categoryName'])->implode(' › '),
        ])->values()->all();
    }

    /** @return list<array{name: string, required: bool, recommended: bool, values: list<string>, free: bool}> */
    public function aspects(string $categoryId): array
    {
        $json = $this->client->rest('GET', '/commerce/taxonomy/v1/category_tree/'.self::CATEGORY_TREE_DE.'/get_item_aspects_for_category', ['category_id' => $categoryId], appToken: true);

        return collect($json['aspects'] ?? [])->map(fn ($a) => [
            'name' => $a['localizedAspectName'],
            'required' => (bool) ($a['aspectConstraint']['aspectRequired'] ?? false),
            'recommended' => ($a['aspectConstraint']['aspectUsage'] ?? '') === 'RECOMMENDED',
            'free' => ($a['aspectConstraint']['aspectMode'] ?? 'FREE_TEXT') === 'FREE_TEXT',
            'values' => collect($a['aspectValues'] ?? [])->pluck('localizedValue')->take(60)->values()->all(),
        ])->filter(fn ($a) => $a['required'] || $a['recommended'])->values()->all();
    }

    /** Vorschlag für Artikelmerkmale aus Marke und den Merkmalen des Artikels. */
    public function prefillSpecifics(Product $product, array $aspects): array
    {
        $attrs = $product->attributeValues->mapWithKeys(fn ($a) => [mb_strtolower($a->key) => $a->value]);
        $out = [];
        foreach ($aspects as $a) {
            $key = mb_strtolower($a['name']);
            $value = $attrs[$key] ?? null;
            if ($value === null && in_array($key, ['marke', 'brand'], true)) {
                $value = $product->vendor;
            }
            if ($value === null && $key === 'herstellernummer') {
                $value = $product->variants->first()?->sku;
            }
            $out[$a['name']] = (string) $value;
        }

        return $out;
    }

    /**
     * Legt ein Festpreis-Angebot an (mit Varianten, wenn der Artikel mehrere hat).
     * Mit $verifyOnly prüft eBay nur und nennt Gebühren oder Fehler.
     *
     * @param  array<string, string>  $specifics
     * @return array{item_id: ?string, fees: ?float, warnings: list<string>}
     */
    public function createListing(Product $product, string $categoryId, array $specifics, bool $verifyOnly = false): array
    {
        $c = $this->client->connection();
        foreach (['fulfillment_policy', 'payment_policy', 'return_policy', 'postal_code', 'location'] as $k) {
            if (blank($c->setting($k))) {
                throw new MarketplaceException('In den eBay-Einstellungen fehlen Versand-, Zahlungs- oder Rücknahmerichtlinie bzw. Artikelstandort.');
            }
        }
        $channel = $this->channel();
        $variants = $product->variants()->with('channelPrices')->get();
        foreach ($variants as $v) {
            if (blank($v->sku)) {
                throw new MarketplaceException('Jede Variante braucht eine SKU, sonst kann Billbee den Bestand nicht zuordnen.');
            }
            if ((int) $v->stock <= 0) {
                throw new MarketplaceException("Variante {$v->label()} hat laut Billbee keinen Bestand.");
            }
        }
        $x = fn ($v) => EbayClient::x($v);
        $specificsXml = collect($specifics)->filter(fn ($v) => trim((string) $v) !== '')
            ->map(fn ($v, $k) => '<NameValueList><Name>'.$x($k).'</Name><Value>'.$x(mb_substr(trim($v), 0, 65)).'</Value></NameValueList>')->implode('');
        $pictures = $product->images()->limit(12)->pluck('url')->map(fn ($u) => '<PictureURL>'.$x($u).'</PictureURL>')->implode('');
        $multi = $variants->count() > 1;

        $item = '<Title>'.$x(mb_substr($product->title, 0, 80)).'</Title>'
            .'<Description><![CDATA['.str_replace(']]>', '', (string) $product->description_html).']]></Description>'
            .'<PrimaryCategory><CategoryID>'.$x($categoryId).'</CategoryID></PrimaryCategory>'
            .'<ConditionID>'.(int) $c->setting('condition', 1000).'</ConditionID>'
            .'<Country>DE</Country><Currency>EUR</Currency><Site>Germany</Site>'
            .'<Location>'.$x($c->setting('location')).'</Location><PostalCode>'.$x($c->setting('postal_code')).'</PostalCode>'
            .'<ListingDuration>GTC</ListingDuration><ListingType>FixedPriceItem</ListingType>'
            .($specificsXml ? '<ItemSpecifics>'.$specificsXml.'</ItemSpecifics>' : '')
            .($pictures ? '<PictureDetails>'.$pictures.'</PictureDetails>' : '')
            .'<SellerProfiles>'
            .'<SellerShippingProfile><ShippingProfileID>'.$x($c->setting('fulfillment_policy')).'</ShippingProfileID></SellerShippingProfile>'
            .'<SellerReturnProfile><ReturnProfileID>'.$x($c->setting('return_policy')).'</ReturnProfileID></SellerReturnProfile>'
            .'<SellerPaymentProfile><PaymentProfileID>'.$x($c->setting('payment_policy')).'</PaymentProfileID></SellerPaymentProfile>'
            .'</SellerProfiles>';

        if ($multi) {
            $optionNames = collect($product->options ?? [])->pluck('name')->values();
            if ($optionNames->isEmpty()) {
                $optionNames = collect(['Variante']);
            }
            $set = '';
            foreach ($optionNames as $i => $name) {
                $values = $variants->pluck('option'.($i + 1))->filter()->unique()->values();
                $set .= '<NameValueList><Name>'.$x($name).'</Name>'.$values->map(fn ($v) => '<Value>'.$x($v).'</Value>')->implode('').'</NameValueList>';
            }
            $vars = '';
            foreach ($variants as $v) {
                $spec = '';
                foreach ($optionNames as $i => $name) {
                    $spec .= '<NameValueList><Name>'.$x($name).'</Name><Value>'.$x($v->{'option'.($i + 1)} ?? $v->label()).'</Value></NameValueList>';
                }
                $vars .= '<Variation><SKU>'.$x($v->sku).'</SKU>'
                    .'<StartPrice>'.number_format($v->priceOn($channel), 2, '.', '').'</StartPrice>'
                    .'<Quantity>'.(int) $v->stock.'</Quantity>'
                    .'<VariationSpecifics>'.$spec.'</VariationSpecifics>'
                    .($v->barcode ? '<VariationProductListingDetails><EAN>'.$x($v->barcode).'</EAN></VariationProductListingDetails>' : '')
                    .'</Variation>';
            }
            $item .= '<Variations><VariationSpecificsSet>'.$set.'</VariationSpecificsSet>'.$vars.'</Variations>';
        } else {
            $v = $variants->first();
            $item .= '<SKU>'.$x($v->sku).'</SKU>'
                .'<StartPrice currencyID="EUR">'.number_format($v->priceOn($channel), 2, '.', '').'</StartPrice>'
                .'<Quantity>'.(int) $v->stock.'</Quantity>'
                .($v->barcode ? '<ProductListingDetails><EAN>'.$x($v->barcode).'</EAN></ProductListingDetails>' : '');
        }

        $doc = $this->client->trading($verifyOnly ? 'VerifyAddFixedPriceItem' : 'AddFixedPriceItem', '<Item>'.$item.'</Item>');
        $warnings = [];
        foreach ($doc->Errors ?? [] as $e) {
            if ((string) $e->SeverityCode === 'Warning') {
                $warnings[] = trim((string) ($e->LongMessage ?: $e->ShortMessage));
            }
        }
        $fees = $this->listingFees($doc);
        $itemId = $verifyOnly ? null : ((string) $doc->ItemID ?: null);

        if ($itemId) {
            foreach ($variants as $v) {
                $price = $v->priceOn($channel);
                Listing::updateOrCreate(['variant_id' => $v->id, 'channel_id' => $channel->id], [
                    'external_id' => $itemId, 'sku' => $v->sku, 'status' => 'active', 'remote_price' => $price, 'pushed_price' => $price,
                    'created_by_us' => true, 'last_error' => null, 'checked_at' => now(), 'price_pushed_at' => now(),
                    'data' => ['variation' => $multi, 'category_id' => $categoryId, 'title' => $product->title],
                ]);
            }
        }

        return ['item_id' => $itemId, 'fees' => $fees, 'warnings' => array_values(array_unique($warnings))];
    }

    private function listingFees(SimpleXMLElement $doc): ?float
    {
        $sum = 0.0;
        $found = false;
        foreach ($doc->Fees->Fee ?? [] as $fee) {
            $found = true;
            $sum += (float) $fee->Fee;
        }

        return $found ? round($sum, 2) : null;
    }
}
