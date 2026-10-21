<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['variant_ids' => 'array'];
    }

    /** Gilt das Bild für diese Variante? Ohne Zuordnung gilt es für alle. */
    public function belongsToVariant(int $variantId): bool
    {
        return empty($this->variant_ids) || in_array($variantId, $this->variant_ids, true);
    }

    public function isAssigned(): bool
    {
        return ! empty($this->variant_ids);
    }

    /** Verkleinerte Fassung über das Shopify-CDN. */
    public function sized(int $width): string
    {
        return $this->url.(str_contains($this->url, '?') ? '&' : '?').'width='.$width;
    }
}
