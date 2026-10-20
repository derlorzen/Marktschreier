<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class MarketplaceConnection extends Model
{
    public const EBAY = 'ebay';
    public const AMAZON = 'amazon';
    public const TIKTOK = 'tiktok';

    protected $guarded = ['id'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'connected_at' => 'datetime', 'listings_checked_at' => 'datetime'];
    }

    public static function for(string $provider): self
    {
        return self::firstOrCreate(['provider' => $provider], ['settings' => []]);
    }

    /** @return array<string, mixed> */
    public function secrets(): array
    {
        if (! $this->credentials) {
            return [];
        }

        return json_decode(Crypt::decryptString($this->credentials), true) ?: [];
    }

    public function storeSecrets(array $secrets): void
    {
        $merged = array_filter(array_replace($this->secrets(), $secrets), fn ($v) => $v !== null);
        $this->credentials = $merged ? Crypt::encryptString(json_encode($merged)) : null;
        $this->save();
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    public function putSettings(array $values): void
    {
        $this->settings = array_replace($this->settings ?? [], $values);
        $this->save();
    }

    protected static function booted(): void
    {
        static::updated(function (self $c) {
            if (! $c->wasChanged('status')) {
                return;
            }
            $alerts = app(\App\Services\Alerts\AlertService::class);
            $name = ['ebay' => 'eBay', 'amazon' => 'Amazon', 'amazon_ads' => 'Amazon Werbung', 'tiktok' => 'TikTok Shop'][$c->provider] ?? $c->provider;
            if ($c->status === 'error') {
                $alerts->raise('connection:'.$c->provider, 'connection', 'bad', "Verbindung zu {$name} gestört", $c->last_error, '/einstellungen');
            } else {
                $alerts->resolve('connection:'.$c->provider);
            }
        });
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected' && filled($this->secrets()['refresh_token'] ?? null);
    }

    public function disconnect(): void
    {
        $this->update(['credentials' => null, 'status' => 'disconnected', 'connected_at' => null, 'account_name' => null, 'last_error' => null]);
    }

    public function channel(): ?Channel
    {
        return Channel::where('key', $this->provider)->first();
    }
}
