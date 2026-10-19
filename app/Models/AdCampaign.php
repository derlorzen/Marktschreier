<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdCampaign extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['budget' => 'decimal:2'];
    }
}
