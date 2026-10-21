<?php

namespace App\Services\Marketplaces\Amazon;

use App\Models\Channel;
use App\Models\Listing;
use App\Models\Variant;
use App\Services\Marketplaces\MarketplaceException;
use Illuminate\Support\Collection;

class AmazonService
{
    public function __construct(private AmazonClient $client)
    {
    }

    public function client(): AmazonClient
    {
        return $this->client;
    }

    public function channel(): Channel
    {
        return Channel::where('key', 'amazon')->firstOrFail();
    }

    /** Verbindungstest: Name des Marktplatzes, auf dem das Konto verkauft. */
    public function refreshAccountName(): string
    {
        $json = $this->client->request('GET', '/sellers/v1/marketplaceParticipations');
        $mine = collect($json['payload'] ?? [])->first(fn ($p) => ($p['marketplace']['id'] ?? null) === $this->client->marketplaceId());
        $name = $mine['storeName'] ?? ($mine['marketplace']['name'] ?? null);
        if (! $mine) {
            throw new MarketplaceException('Das Konto verkauft nicht auf dem eingestellten Marktplatz.');
        }
        $this->client->connection()->update(['account_name' => $name]);

        return (string) $name;
    }

    /**
     * Status, ASIN und Preis für alle eigenen SKUs, je 20 auf einmal.
     *
     * @return array{checked: int, found: int, errors: int}
     */
    public function syncListings(): array
    {
        $channel = $this->channel();
        $sellerId = $this->client->sellerId();
        $mp = $this->client->marketplaceId();
        $stats = ['checked' => 0, 'found' => 0, 'errors' => 0];

        Variant::whereNotNull('sku')->where('sku', '!=', '')->select(['id', 'sku', 'asin', 'product_id'])->chunkById(20, function ($variants) use ($channel, $sellerId, $mp, &$stats) {
            $skus = $variants->pluck('sku')->all();
            $json = $this->client->request('GET', "/listings/2021-08-01/items/{$sellerId}", [
                'marketplaceIds' => $mp, 'identifiers' => implode(',', $skus), 'identifiersType' => 'SKU',
                'includedData' => 'summaries,offers,issues,fulfillmentAvailability', 'pageSize' => 20,
            ]);
            $items = collect($json['items'] ?? [])->keyBy(fn ($i) => mb_strtolower($i['sku'] ?? ''));
            foreach ($variants as $v) {
                $stats['checked']++;
                $item = $items->get(mb_strtolower($v->sku));
                $listing = Listing::firstOrNew(['variant_id' => $v->id, 'channel_id' => $channel->id]);
                if (! $item) {
                    if ($listing->exists && $listing->status !== 'pending') {
                        $listing->update(['status' => 'inactive', 'checked_at' => now()]);
                    }

                    continue;
                }
                $stats['found']++;
                $summary = collect($item['summaries'] ?? [])->firstWhere('marketplaceId', $mp) ?? ($item['summaries'][0] ?? []);
                $offer = collect($item['offers'] ?? [])->first(fn ($o) => ($o['offerType'] ?? 'B2C') === 'B2C');
                $errors = collect($item['issues'] ?? [])->where('severity', 'ERROR')->pluck('message')->all();
                $availability = collect($item['fulfillmentAvailability'] ?? []);
                $fbm = $availability->firstWhere('fulfillmentChannelCode', 'DEFAULT');
                $isFba = $availability->contains(fn ($a) => $a['fulfillmentChannelCode'] !== 'DEFAULT');
                $status = $errors ? 'error' : (in_array('BUYABLE', $summary['status'] ?? [], true) ? 'active' : 'inactive');
                if ($errors) {
                    $stats['errors']++;
                }
                $listing->fill([
                    'external_id' => $summary['asin'] ?? $listing->external_id,
                    'sku' => $item['sku'],
                    'status' => $status,
                    'remote_price' => isset($offer['price']['amount']) ? (float) $offer['price']['amount'] : $listing->remote_price,
                    'last_error' => $errors ? implode(' ', $errors) : null,
                    'remote_quantity' => $fbm ? (int) ($fbm['quantity'] ?? 0) : null,
                    'fulfillment' => $isFba ? 'FBA' : ($fbm ? 'FBM' : $listing->fulfillment),
                    'checked_at' => now(),
                    'data' => array_replace($listing->data ?? [], ['title' => $summary['itemName'] ?? null, 'fulfillment' => $listing->data['fulfillment'] ?? null]),
                ]);
                $listing->adoptRemotePrice();
                $listing->save();
                $this->rememberAsin($v, $summary['asin'] ?? null);
            }
        });
        $this->client->connection()->update(['listings_checked_at' => now()]);
        $stats['fba'] = $this->syncFbaInventory();

        return $stats;
    }

    /**
     * FBA-Bestand je SKU (verfügbar, unterwegs, reserviert), höchstens 50 SKUs je Anfrage.
     */
    public function syncFbaInventory(): int
    {
        $channel = $this->channel();
        $mp = $this->client->marketplaceId();
        $listings = Listing::where('channel_id', $channel->id)->where('fulfillment', 'FBA')->whereNotNull('sku')->get()->keyBy(fn ($l) => mb_strtolower($l->sku));
        $count = 0;
        foreach ($listings->chunk(50) as $chunk) {
            $json = $this->client->request('GET', '/fba/inventory/v1/summaries', [
                'details' => 'true', 'granularityType' => 'Marketplace', 'granularityId' => $mp, 'marketplaceIds' => $mp,
                'sellerSkus' => $chunk->pluck('sku')->implode(','),
            ]);
            foreach ($json['payload']['inventorySummaries'] ?? [] as $s) {
                $l = $listings->get(mb_strtolower($s['sellerSku'] ?? ''));
                if (! $l) {
                    continue;
                }
                $d = $s['inventoryDetails'] ?? [];
                $l->update(['fba' => [
                    'fulfillable' => (int) ($d['fulfillableQuantity'] ?? 0),
                    'inbound' => (int) ($d['inboundWorkingQuantity'] ?? 0) + (int) ($d['inboundShippedQuantity'] ?? 0) + (int) ($d['inboundReceivingQuantity'] ?? 0),
                    'reserved' => (int) ($d['reservedQuantity']['totalReservedQuantity'] ?? 0),
                    'unfulfillable' => (int) ($d['unfulfillableQuantity']['totalUnfulfillableQuantity'] ?? 0),
                    'total' => (int) ($s['totalQuantity'] ?? 0),
                ], 'remote_quantity' => (int) ($d['fulfillableQuantity'] ?? 0)]);
                $count++;
            }
        }

        return $count;
    }

    /** @return list<array{asin: string, title: ?string, brand: ?string, image: ?string}> */
    public function findByGtin(string $gtin): array
    {
        $json = $this->client->request('GET', '/catalog/2022-04-01/items', [
            'identifiers' => $gtin, 'identifiersType' => strlen($gtin) === 12 ? 'UPC' : 'EAN',
            'marketplaceIds' => $this->client->marketplaceId(), 'includedData' => 'summaries,images',
        ]);

        return collect($json['items'] ?? [])->map(fn ($i) => [
            'asin' => $i['asin'],
            'title' => $i['summaries'][0]['itemName'] ?? null,
            'brand' => $i['summaries'][0]['brand'] ?? null,
            'image' => $i['images'][0]['images'][0]['link'] ?? null,
        ])->values()->all();
    }

    /** ASIN an der Variante hinterlegen, wenn dort noch keine steht. Eine gepflegte ASIN wird nie überschrieben. */
    private function rememberAsin(Variant $variant, ?string $asin): void
    {
        if (blank($variant->asin) && Variant::validAsin($asin)) {
            $variant->forceFill(['asin' => $asin])->save();
            \App\Models\ChangeLog::record($variant->product, 'asin', null, $asin, 'amazon', $variant);
        }
    }

    /**
     * Eigenes Angebot an eine bestehende ASIN hängen. Händlerversand mit Startbestand aus Billbee,
     * danach pflegt Billbee den Bestand. Bei FBA verwaltet Amazon die Menge.
     */
    public function createOffer(Variant $variant, string $asin, string $fulfillment = 'FBM'): Listing
    {
        if (blank($variant->sku)) {
            throw new MarketplaceException('Die Variante braucht eine SKU.');
        }
        $channel = $this->channel();
        $mp = $this->client->marketplaceId();
        $price = $variant->priceOn($channel);
        $availability = $fulfillment === 'FBA'
            ? [['fulfillment_channel_code' => 'AMAZON_EU']]
            : [['fulfillment_channel_code' => 'DEFAULT', 'quantity' => max(0, (int) $variant->stock)]];

        $json = $this->client->request('PUT', '/listings/2021-08-01/items/'.$this->client->sellerId().'/'.rawurlencode($variant->sku), [
            'marketplaceIds' => $mp, 'issueLocale' => 'de_DE',
        ], [
            'productType' => 'PRODUCT',
            'requirements' => 'LISTING_OFFER_ONLY',
            'attributes' => [
                'condition_type' => [['value' => 'new_new', 'marketplace_id' => $mp]],
                'merchant_suggested_asin' => [['value' => $asin, 'marketplace_id' => $mp]],
                'purchasable_offer' => [$this->offer($price)],
                'fulfillment_availability' => $availability,
            ],
        ]);

        $errors = collect($json['issues'] ?? [])->where('severity', 'ERROR')->pluck('message')->all();
        $accepted = ($json['status'] ?? '') === 'ACCEPTED' && ! $errors;

        if ($accepted) {
            $this->rememberAsin($variant, $asin);
        }

        return Listing::updateOrCreate(['variant_id' => $variant->id, 'channel_id' => $channel->id], [
            'external_id' => $asin, 'sku' => $variant->sku, 'status' => $accepted ? 'pending' : 'error',
            'pushed_price' => $accepted ? $price : null, 'price_pushed_at' => $accepted ? now() : null,
            'last_error' => $accepted ? null : (implode(' ', $errors) ?: 'Amazon hat das Angebot abgelehnt.'),
            'created_by_us' => true, 'checked_at' => now(),
            'data' => ['fulfillment' => $fulfillment, 'submission_id' => $json['submissionId'] ?? null],
        ]);
    }

    /**
     * @param  Collection<int, Listing>  $listings
     * @return array{ok: int, failed: int}
     */
    public function pushPrices(Collection $listings): array
    {
        $result = ['ok' => 0, 'failed' => 0];
        $sellerId = $this->client->sellerId();
        foreach ($listings->filter(fn (Listing $l) => $l->sku) as $l) {
            $price = $l->variant->priceOn($l->channel);
            try {
                $json = $this->client->request('PATCH', "/listings/2021-08-01/items/{$sellerId}/".rawurlencode($l->sku), [
                    'marketplaceIds' => $this->client->marketplaceId(), 'issueLocale' => 'de_DE',
                ], [
                    'productType' => 'PRODUCT',
                    'patches' => [['op' => 'merge', 'path' => '/attributes/purchasable_offer', 'value' => [$this->offer($price)]]],
                ]);
                $errors = collect($json['issues'] ?? [])->where('severity', 'ERROR')->pluck('message')->all();
                if (($json['status'] ?? '') !== 'ACCEPTED' || $errors) {
                    throw new MarketplaceException(implode(' ', $errors) ?: 'Amazon hat die Preisänderung abgelehnt.');
                }
                $l->update(['pushed_price' => $price, 'remote_price' => $price, 'price_pushed_at' => now(), 'last_error' => null]);
                $result['ok']++;
            } catch (MarketplaceException $e) {
                $l->update(['last_error' => $e->getMessage()]);
                $result['failed']++;
            }
        }

        return $result;
    }

    private function offer(float $price): array
    {
        return [
            'marketplace_id' => $this->client->marketplaceId(),
            'currency' => 'EUR',
            'audience' => 'ALL',
            'our_price' => [['schedule' => [['value_with_tax' => round($price, 2)]]]],
        ];
    }

    /**
     * Titel, Stichpunkte, Suchbegriffe und Beschreibung für alle Amazon-SKUs des Artikels übertragen.
     *
     * @return array{ok: int, failed: int, errors: list<string>}
     */
    public function pushContent(\App\Models\Product $product, \App\Models\ChannelContent $content): array
    {
        $channel = $this->channel();
        $mp = $this->client->marketplaceId();
        $val = fn ($v) => [['value' => $v, 'language_tag' => 'de_DE', 'marketplace_id' => $mp]];
        $patches = [['op' => 'replace', 'path' => '/attributes/item_name', 'value' => $val($content->effectiveTitle())]];
        if ($content->bullets) {
            $patches[] = ['op' => 'replace', 'path' => '/attributes/bullet_point', 'value' => collect($content->bullets)->map(fn ($b) => $val($b)[0])->values()->all()];
        }
        if ($content->search_terms) {
            $patches[] = ['op' => 'replace', 'path' => '/attributes/generic_keyword', 'value' => $val($content->search_terms)];
        }
        if ($desc = trim(strip_tags($content->effectiveDescription(), '<p><br><ul><li><b><strong>'))) {
            $patches[] = ['op' => 'replace', 'path' => '/attributes/product_description', 'value' => $val($desc)];
        }
        $result = ['ok' => 0, 'failed' => 0, 'errors' => []];
        $listings = Listing::where('channel_id', $channel->id)->whereIn('variant_id', $product->variants()->pluck('id'))->whereNotNull('sku')->get();
        foreach ($listings as $l) {
            try {
                $json = $this->client->request('PATCH', '/listings/2021-08-01/items/'.$this->client->sellerId().'/'.rawurlencode($l->sku), [
                    'marketplaceIds' => $mp, 'issueLocale' => 'de_DE',
                ], ['productType' => $l->data['product_type'] ?? 'PRODUCT', 'patches' => $patches]);
                $errors = collect($json['issues'] ?? [])->where('severity', 'ERROR')->pluck('message')->all();
                if (($json['status'] ?? '') !== 'ACCEPTED' || $errors) {
                    throw new MarketplaceException(implode(' ', $errors) ?: 'Abgelehnt');
                }
                $result['ok']++;
            } catch (MarketplaceException $e) {
                $result['failed']++;
                $result['errors'][] = $l->sku.': '.$e->getMessage();
            }
        }

        return $result;
    }
}
