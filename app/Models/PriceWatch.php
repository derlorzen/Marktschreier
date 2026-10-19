<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceWatch extends Model
{
    public const STRATEGIES = [
        'beat' => 'Knapp unter dem günstigsten Mitbewerber (0,01 €)',
        'match' => 'Gleich dem Buy-Box-Preis',
        'watch' => 'Nur beobachten',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'auto' => 'boolean', 'min_price' => 'decimal:2', 'max_price' => 'decimal:2',
            'suggested_price' => 'decimal:2', 'last_result' => 'array', 'checked_at' => 'datetime'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PriceWatchEvent::class)->latest('id');
    }
}
