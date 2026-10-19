<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImage extends Model
{
    protected $guarded = ['id'];

    /** Verkleinerte Fassung über das Shopify-CDN. */
    public function sized(int $width): string
    {
        return $this->url.(str_contains($this->url, '?') ? '&' : '?').'width='.$width;
    }
}
