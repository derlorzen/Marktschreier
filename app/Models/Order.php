<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    /** Billbee-Status, die nicht als Verkauf zählen: gelöscht, storniert. */
    public const NOT_SOLD_STATES = [6, 8];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ordered_at' => 'datetime', 'billbee_modified_at' => 'datetime', 'total_gross' => 'decimal:2', 'shipping_gross' => 'decimal:2'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function scopeSold(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->whereNull('state')->orWhereNotIn('state', self::NOT_SOLD_STATES));
    }
}
