<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Alert extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime', 'mailed_at' => 'datetime'];
    }

    public function readers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'alert_reads')->withPivot('read_at');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('resolved_at');
    }

    public function scopeUnreadFor(Builder $q, User $user): Builder
    {
        return $q->whereNull('resolved_at')->whereDoesntHave('readers', fn ($r) => $r->where('users.id', $user->id));
    }

    public function markReadBy(User $user): void
    {
        $this->readers()->syncWithoutDetaching([$user->id => ['read_at' => now()]]);
    }

    public static function typeLabel(string $type): string
    {
        return [
            'sync_failed' => 'Abgleich fehlgeschlagen', 'connection' => 'Verbindung', 'stockout' => 'Bestand',
            'loss' => 'Verlust', 'listing_error' => 'Angebot', 'oversell' => 'Überverkauf', 'buybox' => 'Buy Box',
            'release' => 'Release', 'calendar' => 'Kalender', 'ads' => 'Werbung', 'backup' => 'Sicherung',
        ][$type] ?? $type;
    }
}
