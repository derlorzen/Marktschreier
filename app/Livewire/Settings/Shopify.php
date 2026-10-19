<?php

namespace App\Livewire\Settings;

use App\Models\Setting;
use App\Models\SyncRun;
use App\Services\Shopify\ShopifyClient;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Shopify')]
class Shopify extends Component
{
    public string $shopifyShop = '';

    public string $shopifyVersion = '';

    public string $shopifyClientId = '';

    /** Geheimnisse werden nie zurück in den Browser geschickt, nur neu gesetzt. */
    public string $shopifyClientSecret = '';

    public string $shopifyToken = '';

    public ?array $test = null;

    public ?string $notice = null;

    public function mount(): void
    {
        $this->shopifyShop = (string) Setting::get('shopify.shop');
        $this->shopifyVersion = (string) Setting::get('shopify.api_version', '2026-10');
        $this->shopifyClientId = (string) Setting::get('shopify.client_id');
    }

    public function save(): void
    {
        $this->shopifyShop = strtolower(trim(preg_replace('#^https?://#', '', $this->shopifyShop), '/ '));
        $this->validate([
            'shopifyShop' => ['nullable', 'regex:/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/i'],
            'shopifyVersion' => ['required', 'regex:/^\d{4}-(01|04|07|10)$/'],
            'shopifyClientId' => ['nullable', 'string', 'max:255'],
            'shopifyClientSecret' => ['nullable', 'string', 'max:255'],
            'shopifyToken' => ['nullable', 'string', 'max:255'],
        ], [
            'shopifyShop.regex' => 'Bitte die myshopify-Adresse angeben, z. B. johtomarket.myshopify.com.',
            'shopifyVersion.regex' => 'Format JJJJ-MM, z. B. 2026-10.',
        ]);
        Setting::put('shopify.shop', $this->shopifyShop ?: null);
        Setting::put('shopify.api_version', $this->shopifyVersion);
        Setting::put('shopify.client_id', trim($this->shopifyClientId) ?: null);
        foreach (['shopify.client_secret' => 'shopifyClientSecret', 'shopify.token' => 'shopifyToken'] as $key => $prop) {
            if (trim($this->{$prop}) !== '') {
                Setting::put($key, trim($this->{$prop}));
            }
            $this->{$prop} = '';
        }
        $this->test = null;
        $this->notice = 'Gespeichert.';
    }

    public function forgetToken(): void
    {
        Setting::put('shopify.token', null);
        $this->notice = 'Festes Token entfernt.';
    }

    public function testConnection(): void
    {
        try {
            $this->test = ['ok' => true, 'text' => 'Verbunden mit „'.app(ShopifyClient::class)->shopName().'“.'];
        } catch (\Throwable $e) {
            $this->test = ['ok' => false, 'text' => $e->getMessage()];
        }
    }

    public function render()
    {
        return view('livewire.settings.shopify', [
            'hasToken' => filled(Setting::get('shopify.token')),
            'hasSecret' => filled(Setting::get('shopify.client_secret')),
            'lastImport' => SyncRun::lastSuccessful(SyncRun::TYPE_SHOPIFY_IMPORT),
        ]);
    }
}
