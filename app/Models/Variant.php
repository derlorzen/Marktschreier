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
            'stock_locations' => 'array',
            'billbee_synced_at' => 'datetime',
            'is_main' => 'boolean',
            'active' => 'boolean',
            'weight_g' => 'integer',
            'length_mm' => 'integer',
            'width_mm' => 'integer',
            'height_mm' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Jeder Artikel hat wie bei PlentyONE genau eine Hauptvariante; die erste wird es automatisch.
        static::created(function (Variant $variant) {
            if (! $variant->is_main && ! static::where('product_id', $variant->product_id)->where('is_main', true)->exists()) {
                $variant->forceFill(['is_main' => true])->saveQuietly();
            }
        });
    }

    public static function pushableFields(): array
    {
        return ['price', 'compare_at_price', 'sku', 'barcode'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function priceWatch(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PriceWatch::class);
    }

    /** Eigene Merkmale der Variante (ohne die geerbten des Artikels). */
    public function attributeValues(): HasMany
    {
        return $this->hasMany(VariantAttribute::class)->orderBy('key');
    }

    /**
     * Merkmale wie bei PlentyONE: Was die Variante nicht selbst hat, erbt sie vom Artikel.
     *
     * @return array<string, string|null> [Merkmal => Wert]
     */
    public function effectiveAttributes(): array
    {
        $product = $this->product;
        $inherited = $product ? $product->attributeValues->pluck('value', 'key')->all() : [];
        $own = $this->attributeValues->pluck('value', 'key')->all();
        $all = array_replace($inherited, $own);
        ksort($all, SORT_NATURAL | SORT_FLAG_CASE);

        return $all;
    }

    /** Maße für die Anzeige, z. B. "90 × 65 × 20 mm". */
    public function dimensionsLabel(): ?string
    {
        $dims = array_filter([$this->length_mm, $this->width_mm, $this->height_mm], fn ($d) => $d !== null);

        return count($dims) === 3 ? implode(' × ', $dims).' mm' : null;
    }

    public static function validAsin(?string $asin): bool
    {
        return $asin !== null && preg_match('/^(B0[A-Z0-9]{8}|\d{9}[\dX])$/', $asin) === 1;
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
