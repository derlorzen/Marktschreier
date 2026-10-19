<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Listing extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'remote_price' => 'decimal:2', 'pushed_price' => 'decimal:2', 'data' => 'array', 'created_by_us' => 'boolean',
            'checked_at' => 'datetime', 'price_pushed_at' => 'datetime',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * Wurde der Preis in Marktschreier geändert, seit er zuletzt übertragen bzw. gelesen wurde?
     * Ändert der Marktplatz selbst den Preis (z. B. ein Repricer), gilt das nicht als offen.
     */
    public function pricePending(?float $localPrice = null): bool
    {
        if (! $this->external_id || $this->status === 'inactive') {
            return false;
        }
        $localPrice ??= $this->variant->priceOn($this->channel);
        $base = $this->pushed_price ?? $this->remote_price;

        return $base === null || abs((float) $base - $localPrice) >= 0.005;
    }

    /** Hat der Marktplatz den Preis seit der letzten Übertragung selbst geändert? */
    public function changedRemotely(): bool
    {
        return $this->remote_price !== null && $this->pushed_price !== null
            && abs((float) $this->remote_price - (float) $this->pushed_price) >= 0.005;
    }

    /**
     * Beim ersten Fund eines Angebots: dessen Preis als Ausgangsbasis übernehmen,
     * damit nichts als offene Änderung erscheint, was niemand in Marktschreier geändert hat.
     */
    public function adoptRemotePrice(): void
    {
        if ($this->pushed_price !== null || $this->remote_price === null) {
            return;
        }
        $this->pushed_price = $this->remote_price;
        $variant = $this->variant;
        $has = ChannelPrice::where('variant_id', $variant->id)->where('channel_id', $this->channel_id)->exists();
        if (! $has && abs((float) $variant->price - (float) $this->remote_price) >= 0.005) {
            ChannelPrice::create(['variant_id' => $variant->id, 'channel_id' => $this->channel_id, 'price' => $this->remote_price, 'source' => 'import']);
        }
    }

    public function statusLabel(): array
    {
        return [
            'active' => ['Online', 'badge-ok'],
            'inactive' => ['Inaktiv', ''],
            'pending' => ['In Bearbeitung', 'badge-amber'],
            'error' => ['Fehler', 'badge-bad'],
            'unknown' => ['Unbekannt', ''],
        ][$this->status] ?? [$this->status, ''];
    }
}
