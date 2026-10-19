<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Channel extends Model
{
    public const SHOPIFY = 'shopify';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'fee_percent' => 'decimal:3', 'fee_fixed' => 'decimal:2', 'shipping_cost' => 'decimal:2'];
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true)->orderBy('position');
    }

    public function isShopify(): bool
    {
        return $this->key === self::SHOPIFY;
    }

    /** Passt eine Plattform aus Billbee (z. B. "Amazon", "ebay", "Shopify") zu diesem Kanal? */
    public function matchesPlatform(?string $platform): bool
    {
        $platform = mb_strtolower(trim((string) $platform));
        if ($platform === '') {
            return false;
        }
        foreach (array_filter(array_map('trim', explode(',', mb_strtolower((string) $this->billbee_platforms)))) as $needle) {
            if (str_contains($platform, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function feeText(): string
    {
        $parts = [];
        if ((float) $this->fee_percent) {
            $parts[] = rtrim(rtrim(number_format((float) $this->fee_percent, 2, ',', ''), '0'), ',').' %';
        }
        if ((float) $this->fee_fixed) {
            $parts[] = number_format((float) $this->fee_fixed, 2, ',', '').' €';
        }

        return $parts ? implode(' + ', $parts) : 'keine';
    }
}
