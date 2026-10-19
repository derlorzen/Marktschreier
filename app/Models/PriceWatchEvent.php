<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceWatchEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['won' => 'boolean', 'created_at' => 'datetime'];
    }
}
