<?php

namespace App\Services\Marketplaces;

use App\Models\Channel;
use App\Models\Listing;
use App\Models\MarketplaceConnection;
use App\Services\Marketplaces\Amazon\AmazonService;
use App\Services\Marketplaces\Ebay\EbayService;
use Illuminate\Support\Collection;

/** Gemeinsamer Einstieg für alle Marktplätze: Listings lesen, offene Preise übertragen. */
class MarketplaceManager
{
    public const PROVIDERS = [MarketplaceConnection::EBAY, MarketplaceConnection::AMAZON];

    public function __construct(private EbayService $ebay, private AmazonService $amazon)
    {
    }

    public function service(string $provider): EbayService|AmazonService
    {
        return match ($provider) {
            MarketplaceConnection::EBAY => $this->ebay,
            MarketplaceConnection::AMAZON => $this->amazon,
        };
    }

    public function isConnected(string $provider): bool
    {
        return MarketplaceConnection::for($provider)->isConnected();
    }

    /** @return Collection<int, string> verbundene Anbieter */
    public function connected(): Collection
    {
        return collect(self::PROVIDERS)->filter(fn ($p) => $this->isConnected($p))->values();
    }

    /** @return Collection<int, Listing> Angebote, deren Preis von Marktschreier abweicht */
    public function pendingPrices(string $provider, ?array $productIds = null): Collection
    {
        $channel = Channel::where('key', $provider)->first();
        if (! $channel) {
            return collect();
        }

        return Listing::with(['variant.channelPrices', 'channel'])
            ->where('channel_id', $channel->id)->whereNotNull('external_id')->whereIn('status', ['active', 'pending', 'error'])
            ->when($productIds, fn ($q) => $q->whereHas('variant', fn ($v) => $v->whereIn('product_id', $productIds)))
            ->get()->filter(fn (Listing $l) => $l->pricePending())->values();
    }

    public function syncListings(string $provider): array
    {
        return $this->service($provider)->syncListings();
    }

    public function pushPrices(string $provider, ?array $productIds = null): array
    {
        $pending = $this->pendingPrices($provider, $productIds);

        return $pending->isEmpty() ? ['ok' => 0, 'failed' => 0] : $this->service($provider)->pushPrices($pending);
    }
}
