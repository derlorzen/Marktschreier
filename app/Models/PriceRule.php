<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceRule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['min_profit' => 'decimal:2'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PriceRuleLine::class);
    }

    public function lineFor(Channel $channel): ?PriceRuleLine
    {
        return $this->lines->firstWhere('channel_id', $channel->id);
    }
}
