<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Merkmal einer einzelnen Variante. Überschreibt das gleichnamige Merkmal des Artikels. */
class VariantAttribute extends Model
{
    protected $guarded = ['id'];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
