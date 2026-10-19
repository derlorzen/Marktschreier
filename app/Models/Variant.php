<?php

namespace App\Models;

use App\Models\Concerns\TracksShopifySync;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Variant extends Model
{
    use TracksShopifySync;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'cost_net' => 'decimal:4',
            'synced_snapshot' => 'array',
            'billbee_synced_at' => 'datetime',
        ];
    }

    public static function pushableFields(): array
    {
        return ['price', 'compare_at_price', 'sku', 'barcode'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function channelPrices(): HasMany
    {
        return $this->hasMany(ChannelPrice::class);
    }

    /** Aktueller Preis auf einem Kanal. Ohne eigenen Kanalpreis gilt der Shopify-Preis. */
    public function priceOn(Channel $channel): float
    {
        if (! $channel->isShopify()) {
            $own = $this->relationLoaded('channelPrices')
                ? $this->channelPrices->firstWhere('channel_id', $channel->id)
                : $this->channelPrices()->where('channel_id', $channel->id)->first();
            if ($own) {
                return (float) $own->price;
            }
        }

        return (float) $this->price;
    }

    public function label(): string
    {
        // "Default Title" ist Shopifys Platzhalter für Artikel ohne Varianten.
        $opts = array_filter([$this->option1, $this->option2, $this->option3], fn ($o) => $o && $o !== 'Default Title');

        return $opts ? implode(' / ', $opts) : ($this->title && $this->title !== 'Default Title' ? $this->title : 'Standard');
    }

    /** Rohertrag netto je Stück im Shop (Preis brutto inkl. 19 % MwSt.). */
    public function marginNet(float $vatRate = 19.0): ?float
    {
        if ($this->cost_net === null) {
            return null;
        }

        return round((float) $this->price / (1 + $vatRate / 100) - (float) $this->cost_net, 2);
    }
}
