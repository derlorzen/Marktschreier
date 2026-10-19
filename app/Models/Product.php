<?php

namespace App\Models;

use App\Models\Concerns\TracksShopifySync;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use TracksShopifySync;

    public const STATUS_SYNCED = 'synced';
    public const STATUS_PENDING = 'pending';
    public const STATUS_ERROR = 'error';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_NEW = 'new';     // nur in Marktschreier, noch nicht in Shopify angelegt

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'options' => 'array',
            'synced_snapshot' => 'array',
            'shopify_updated_at' => 'datetime',
            'last_pushed_at' => 'datetime',
        ];
    }

    public static function pushableFields(): array
    {
        return ['title', 'description_html', 'vendor', 'product_type', 'tags', 'status', 'seo_title', 'seo_description'];
    }

    /** Lesbare Namen für Oberfläche und Protokoll. */
    public static function fieldLabel(string $field): string
    {
        return [
            'title' => 'Titel', 'description_html' => 'Beschreibung', 'vendor' => 'Marke', 'product_type' => 'Produktart',
            'tags' => 'Tags', 'status' => 'Status', 'seo_title' => 'SEO-Titel', 'seo_description' => 'SEO-Beschreibung',
            'price' => 'Preis', 'compare_at_price' => 'Streichpreis', 'sku' => 'SKU', 'barcode' => 'GTIN/EAN',
        ][$field] ?? $field;
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class)->orderBy('position');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttribute::class)->orderBy('key');
    }

    public function channelContents(): HasMany
    {
        return $this->hasMany(ChannelContent::class);
    }

    public function contentFor(Channel $channel): ChannelContent
    {
        $c = $this->channelContents->firstWhere('channel_id', $channel->id)
            ?? new ChannelContent(['product_id' => $this->id, 'channel_id' => $channel->id]);
        $c->setRelation('product', $this)->setRelation('channel', $channel);

        return $c;
    }

    public function changeLogs(): HasMany
    {
        return $this->hasMany(ChangeLog::class)->latest('created_at')->latest('id');
    }

    /**
     * Alle offenen Änderungen dieses Artikels inkl. Varianten.
     *
     * @return array{product: array<string, array{old: mixed, new: mixed}>, variants: array<int, array<string, array{old: mixed, new: mixed}>>}
     */
    public function allPendingChanges(): array
    {
        $variants = [];
        foreach ($this->variants as $variant) {
            if ($changes = $variant->pendingChanges()) {
                $variants[$variant->id] = $changes;
            }
        }

        return ['product' => $this->pendingChanges(), 'variants' => $variants];
    }

    /** Prüfsumme der Merkmale, um geänderte Metafelder zu erkennen */
    public function attributesHash(): ?string
    {
        $attrs = $this->attributeValues()->orderBy('key')->pluck('value', 'key')->all();

        return $attrs ? md5(json_encode($attrs)) : null;
    }

    public function attributesPending(): bool
    {
        return $this->shopify_id !== null && $this->attributesHash() !== $this->attributes_pushed_hash;
    }

    public function hasPendingChanges(): bool
    {
        $all = $this->allPendingChanges();

        return $all['product'] !== [] || $all['variants'] !== [] || $this->attributesPending();
    }

    /** Status neu bestimmen, ohne einen Fehler- oder Konfliktzustand stillschweigend zu verwerfen. */
    public function refreshPushStatus(): void
    {
        if (! $this->shopify_id) {
            $this->push_status = self::STATUS_NEW;

            return;
        }
        if (in_array($this->push_status, [self::STATUS_ERROR, self::STATUS_CONFLICT], true) && $this->hasPendingChanges()) {
            return;
        }
        $this->push_status = $this->hasPendingChanges() ? self::STATUS_PENDING : self::STATUS_SYNCED;
        if ($this->push_status === self::STATUS_SYNCED) {
            $this->push_error = null;
        }
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }
        foreach (preg_split('/\s+/', $term) as $word) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $word).'%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('title', 'like', $like)
                    ->orWhere('vendor', 'like', $like)
                    ->orWhereHas('variants', fn (Builder $v) => $v->where('sku', 'like', $like)->orWhere('barcode', 'like', $like));
            });
        }

        return $query;
    }
}
