<?php

namespace App\Services\Marketplaces\TikTok;

use App\Models\Channel;
use App\Models\Listing;
use App\Models\Variant;
use App\Services\Marketplaces\MarketplaceException;
use Illuminate\Support\Collection;

/** TikTok Shop: Angebote lesen und Preise übertragen. Bestand bleibt bei Billbee bzw. dem Shop. */
class TikTokService
{
    public function __construct(private TikTokClient $client)
    {
    }

    public function client(): TikTokClient
    {
        return $this->client;
    }

    public function channel(): Channel
    {
        return Channel::where('key', 'tiktok')->firstOrFail();
    }

    /** @return array{listings: int, matched: int, unmatched: int} */
    public function syncListings(): array
    {
        $channel = $this->channel();
        $bySku = Variant::whereNotNull('sku')->pluck('id', 'sku')->mapWithKeys(fn ($id, $sku) => [mb_strtolower(trim($sku)) => $id]);
        $stats = ['listings' => 0, 'matched' => 0, 'unmatched' => 0];
        $seen = [];
        $token = null;
        do {
            $query = ['page_size' => 100] + ($token ? ['page_token' => $token] : []);
            $data = $this->client->request('POST', '/product/202309/products/search', $query, ['status' => 'ACTIVATE']);
            foreach ($data['products'] ?? [] as $product) {
                $stats['listings']++;
                foreach ($product['skus'] ?? [] as $sku) {
                    $variantId = $bySku[mb_strtolower(trim((string) ($sku['seller_sku'] ?? '')))] ?? null;
                    if (! $variantId) {
                        $stats['unmatched']++;

                        continue;
                    }
                    $price = $sku['price']['sale_price'] ?? $sku['price']['tax_exclusive_price'] ?? $sku['price']['amount'] ?? null;
                    $listing = Listing::firstOrNew(['variant_id' => $variantId, 'channel_id' => $channel->id]);
                    $listing->fill([
                        'external_id' => (string) $product['id'], 'sku' => $sku['seller_sku'], 'status' => 'active',
                        'remote_price' => $price !== null ? (float) $price : $listing->remote_price,
                        'last_error' => null, 'checked_at' => now(),
                        'data' => array_replace($listing->data ?? [], ['sku_id' => (string) $sku['id'], 'title' => $product['title'] ?? null]),
                    ]);
                    $listing->adoptRemotePrice();
                    $listing->save();
                    $seen[] = $listing->id;
                    $stats['matched']++;
                }
            }
            $token = $data['next_page_token'] ?? null;
        } while ($token);

        Listing::where('channel_id', $channel->id)->whereNotIn('id', $seen)->where('status', 'active')->update(['status' => 'inactive', 'checked_at' => now()]);
        $this->client->connection()->update(['listings_checked_at' => now()]);

        return $stats;
    }

    /**
     * @param  Collection<int, Listing>  $listings
     * @return array{ok: int, failed: int}
     */
    public function pushPrices(Collection $listings): array
    {
        $result = ['ok' => 0, 'failed' => 0];
        foreach ($listings->filter(fn (Listing $l) => $l->external_id && ($l->data['sku_id'] ?? null))->groupBy('external_id') as $productId => $group) {
            $skus = $group->map(fn (Listing $l) => ['id' => $l->data['sku_id'], 'price' => ['amount' => number_format($l->variant->priceOn($l->channel), 2, '.', ''), 'currency' => 'EUR']])->values()->all();
            try {
                $this->client->request('POST', '/product/202309/products/'.rawurlencode((string) $productId).'/prices/update', [], ['skus' => $skus]);
                foreach ($group as $l) {
                    $p = $l->variant->priceOn($l->channel);
                    $l->update(['pushed_price' => $p, 'remote_price' => $p, 'price_pushed_at' => now(), 'last_error' => null]);
                    $result['ok']++;
                }
            } catch (MarketplaceException $e) {
                foreach ($group as $l) {
                    $l->update(['last_error' => $e->getMessage()]);
                    $result['failed']++;
                }
            }
        }

        return $result;
    }

    public function refreshAccountName(): string
    {
        return (string) $this->client->connection()->account_name;
    }
}
