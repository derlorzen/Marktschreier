<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelContent extends Model
{
    /** Längen laut Kanalvorgaben */
    public const LIMITS = [
        'amazon' => ['title' => 200, 'bullet' => 250, 'bullets' => 5, 'search_terms' => 249],
        'ebay' => ['title' => 80],
        'tiktok' => ['title' => 255],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['bullets' => 'array', 'pushed_at' => 'datetime'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function effectiveTitle(): string
    {
        return $this->title ?: $this->product->title;
    }

    public function effectiveDescription(): string
    {
        return $this->description_html ?: (string) $this->product->description_html;
    }
}
