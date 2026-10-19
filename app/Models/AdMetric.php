<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdMetric extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'cost' => 'decimal:2', 'sales' => 'decimal:2'];
    }
}
