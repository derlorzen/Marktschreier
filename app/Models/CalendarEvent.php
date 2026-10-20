<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarEvent extends Model
{
    public const TYPES = [
        'release' => 'Set-Release',
        'preorder' => 'Vorbestellung',
        'aktion' => 'Aktion',
        'messe' => 'Messe oder Turnier',
        'sonstiges' => 'Sonstiges',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'product_ids' => 'array'];
    }

    public function products()
    {
        return Product::whereIn('id', $this->product_ids ?? [])->get();
    }

    public function daysUntil(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->starts_on, false);
    }
}
