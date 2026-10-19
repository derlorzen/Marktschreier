<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdSearchTerm extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cost' => 'float', 'sales' => 'float', 'clicks' => 'integer', 'orders' => 'integer'];
    }
}
