<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpportunityDismissal extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['until' => 'datetime'];
    }

    /** @return array<string, true> aktuell ausgeblendete Schlüssel */
    public static function activeKeys(): array
    {
        return self::where(fn ($q) => $q->whereNull('until')->orWhere('until', '>', now()))
            ->pluck('key')->flip()->map(fn () => true)->all();
    }
}
