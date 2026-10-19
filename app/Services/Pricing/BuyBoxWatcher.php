<?php

namespace App\Services\Pricing;

use App\Models\Channel;
use App\Models\Listing;
use App\Models\PriceWatch;
use App\Models\PriceWatchEvent;
use App\Services\Marketplaces\Amazon\AmazonClient;
use App\Services\Marketplaces\Amazon\AmazonService;
use App\Services\ProductEditor;
use Illuminate\Support\Collection;

/**
 * Beobachtet die Angebote auf Amazon und schlägt Preise vor bzw. setzt sie innerhalb fester Grenzen.
 * Nie unter den Mindestpreis, nie über den Höchstpreis, nie mehr als X % Änderung pro Tag.
 */
class BuyBoxWatcher
{
    public function __construct(private AmazonClient $client, private AmazonService $amazon, private ProductEditor $editor, private PriceCalculator $calc)
    {
    }

    /** Vorschlag für den Mindestpreis: kein Verlust nach EK, Gebühren und Versand. */
    public function suggestMinPrice(\App\Models\Variant $variant): ?float
    {
        $channel = Channel::where('key', 'amazon')->first();
        if (! $channel || $variant->cost_net === null) {
            return null;
        }
        $vat = 1 + $this->calc->vat() / 100;
        $den = 1 / $vat - (float) $channel->fee_percent / 100;

        return $den > 0 ? PriceCalculator::roundToEnding(((float) $variant->cost_net + $this->calc->fixedCosts($channel)) / $den, '99') : null;
    }

    /** @return array{checked: int, applied: int, suggested: int, lost: int} */
    public function run(?Collection $watches = null): array
    {
        $channel = Channel::where('key', 'amazon')->firstOrFail();
        $watches ??= PriceWatch::where('active', true)->get();
        $watches->load('variant.channelPrices', 'variant.product');
        $listings = Listing::where('channel_id', $channel->id)->whereIn('variant_id', $watches->pluck('variant_id'))->whereNotNull('external_id')->get()->keyBy('variant_id');
        $stats = ['checked' => 0, 'applied' => 0, 'suggested' => 0, 'lost' => 0];

        $byAsin = $watches->filter(fn ($w) => $listings->has($w->variant_id))->groupBy(fn ($w) => $listings[$w->variant_id]->external_id);
        foreach ($byAsin->keys()->chunk(20) as $asins) {
            $json = $this->client->request('POST', '/batches/products/pricing/v0/itemOffers', [], ['requests' => $asins->values()->map(fn ($asin) => [
                'uri' => '/products/pricing/v0/items/'.$asin.'/offers', 'method' => 'GET',
                'MarketplaceId' => $this->client->marketplaceId(), 'ItemCondition' => 'New', 'CustomerType' => 'Consumer',
            ])->all()]);
            foreach ($json['responses'] ?? [] as $resp) {
                $payload = $resp['body']['payload'] ?? null;
                $asin = $payload['ASIN'] ?? ($resp['request']['Asin'] ?? null);
                if (! $payload || ! $asin || ! isset($byAsin[$asin])) {
                    continue;
                }
                foreach ($byAsin[$asin] as $watch) {
                    $r = $this->evaluate($watch, $listings[$watch->variant_id], $payload, $channel);
                    $stats['checked']++;
                    $stats[$r] = ($stats[$r] ?? 0) + 1;
                    if (! ($watch->last_result['won'] ?? true)) {
                        $stats['lost']++;
                    }
                }
            }
        }

        return $stats;
    }

    /** @return string applied | suggested | checked */
    public function evaluate(PriceWatch $watch, Listing $listing, array $payload, Channel $channel): string
    {
        $variant = $watch->variant;
        $myPrice = $variant->priceOn($channel);
        $offers = collect($payload['Offers'] ?? []);
        $mine = $offers->firstWhere('MyOffer', true);
        $myShipping = (float) ($mine['Shipping']['Amount'] ?? 0);
        $landed = fn ($o) => (float) ($o['ListingPrice']['Amount'] ?? 0) + (float) ($o['Shipping']['Amount'] ?? 0);
        $competitors = $offers->reject(fn ($o) => ! empty($o['MyOffer']));
        $lowest = $competitors->map($landed)->filter()->min();
        $buyBox = collect($payload['Summary']['BuyBoxPrices'] ?? [])->first(fn ($b) => strcasecmp($b['condition'] ?? '', 'new') === 0);
        $buyBoxPrice = $buyBox ? (float) ($buyBox['LandedPrice']['Amount'] ?? 0) : null;
        $won = (bool) ($mine['IsBuyBoxWinner'] ?? false);

        // Zielpreis inkl. Versand, dann eigenen Versand abziehen
        $targetLanded = null;
        $note = null;
        if ($watch->strategy === 'beat' && $lowest !== null) {
            $targetLanded = $lowest - 0.01;
        } elseif ($watch->strategy === 'match' && $buyBoxPrice !== null && ! $won) {
            $targetLanded = $buyBoxPrice;
        }
        $target = $targetLanded !== null ? round($targetLanded - $myShipping, 2) : null;
        if ($target === null && $watch->strategy === 'beat' && $lowest === null) {
            // Kein Mitbewerber: Richtung Höchstpreis gehen, falls gesetzt
            $target = $watch->max_price !== null ? (float) $watch->max_price : null;
            $note = 'Keine anderen Anbieter';
        }
        if ($target !== null) {
            if ($target < (float) $watch->min_price) {
                $target = (float) $watch->min_price;
                $note = 'Mindestpreis erreicht, Mitbewerber ist günstiger';
            }
            if ($watch->max_price !== null && $target > (float) $watch->max_price) {
                $target = (float) $watch->max_price;
            }
        }

        $result = [
            'won' => $won, 'my_price' => $myPrice, 'buybox' => $buyBoxPrice, 'lowest' => $lowest,
            'offers' => (int) ($payload['Summary']['TotalOfferCount'] ?? $offers->count()), 'note' => $note,
            'buybox_seller' => $buyBox['sellerId'] ?? null,
        ];
        $action = 'checked';
        $suggested = null;
        if ($watch->strategy !== 'watch' && $target !== null && abs($target - $myPrice) >= 0.01) {
            $suggested = $target;
            $action = 'suggested';
            if ($watch->auto) {
                $limit = $this->dailyLimitOk($watch, $myPrice, $target);
                if ($limit === true) {
                    $this->editor->setChannelPrice($variant, $channel, number_format($target, 2, '.', ''));
                    $variant->unsetRelation('channelPrices');
                    $listing->setRelation('variant', $variant->fresh('channelPrices'))->setRelation('channel', $channel);
                    $push = $this->amazon->pushPrices(collect([$listing]));
                    $action = $push['ok'] ? 'applied' : 'blocked';
                    $note = $push['ok'] ? $note : 'Amazon hat den Preis abgelehnt: '.$listing->fresh()->last_error;
                    $suggested = $push['ok'] ? null : $suggested;
                } else {
                    $action = 'blocked';
                    $note = $limit;
                }
            }
        }

        $watch->update(['last_result' => array_replace($result, ['note' => $note]), 'suggested_price' => $suggested, 'checked_at' => now()]);
        PriceWatchEvent::create([
            'price_watch_id' => $watch->id, 'my_price' => $myPrice, 'buybox_price' => $buyBoxPrice, 'lowest_competitor' => $lowest,
            'won' => $won, 'new_price' => $target, 'action' => $action, 'note' => $note, 'created_at' => now(),
        ]);

        return $action === 'blocked' ? 'suggested' : $action;
    }

    /** true oder Begründung, warum die automatische Änderung zu groß wäre */
    private function dailyLimitOk(PriceWatch $watch, float $current, float $target): true|string
    {
        $first = PriceWatchEvent::where('price_watch_id', $watch->id)->where('created_at', '>=', now()->startOfDay())->oldest('id')->value('my_price');
        $base = (float) ($first ?? $current);
        $change = abs($target - $base) / max($base, 0.01) * 100;

        return $change <= $watch->max_daily_change
            ? true
            : 'Änderung von '.number_format($change, 0, ',', '.').' % heute über der Grenze von '.$watch->max_daily_change.' %, bitte selbst entscheiden';
    }

    /** Vorschlag von Hand übernehmen und zu Amazon übertragen. */
    public function applySuggestion(PriceWatch $watch, ?int $userId = null): bool
    {
        $channel = Channel::where('key', 'amazon')->firstOrFail();
        $variant = $watch->variant;
        $listing = Listing::where('channel_id', $channel->id)->where('variant_id', $variant->id)->first();
        if (! $watch->suggested_price || ! $listing) {
            return false;
        }
        $this->editor->setChannelPrice($variant, $channel, (string) $watch->suggested_price, $userId);
        $listing->setRelation('variant', $variant->fresh('channelPrices'))->setRelation('channel', $channel);
        $ok = $this->amazon->pushPrices(collect([$listing]))['ok'] === 1;
        if ($ok) {
            PriceWatchEvent::create(['price_watch_id' => $watch->id, 'my_price' => $watch->suggested_price, 'new_price' => $watch->suggested_price,
                'action' => 'applied', 'note' => 'von Hand übernommen', 'created_at' => now()]);
            $watch->update(['suggested_price' => null]);
        }

        return $ok;
    }
}
