<?php

namespace App\Livewire;

use App\Models\Setting;
use App\Services\Billbee\BillbeeClient;
use App\Services\Shopify\ShopifyClient;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Einstellungen')]
class Settings extends Component
{
    public string $shopifyShop = '';

    /** Geheimnisse werden nie zurück in den Browser geschickt, nur neu gesetzt. */
    public string $shopifyToken = '';

    public string $shopifyClientId = '';

    public string $shopifyClientSecret = '';

    public string $shopifyVersion = '';

    public string $billbeeApiKey = '';

    public string $billbeeUser = '';

    public string $billbeePassword = '';

    public string $costMode = 'net';

    public string $vatRate = '19';

    /** @var array<string, array{ok: bool, text: string}> */
    public array $tests = [];

    public ?string $notice = null;

    public function mount(): void
    {
        $this->shopifyShop = (string) Setting::get('shopify.shop');
        $this->shopifyVersion = (string) Setting::get('shopify.api_version', '2026-10');
        $this->shopifyClientId = (string) Setting::get('shopify.client_id');
        $this->billbeeUser = (string) Setting::get('billbee.username');
        $this->costMode = (string) Setting::get('billbee.cost_price_mode', 'net');
        $this->vatRate = (string) Setting::get('billbee.vat_rate', 19);
    }

    protected function rules(): array
    {
        return [
            'shopifyShop' => ['nullable', 'regex:/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/i'],
            'shopifyVersion' => ['required', 'regex:/^\d{4}-(01|04|07|10)$/'],
            'shopifyToken' => ['nullable', 'string', 'max:255'],
            'shopifyClientId' => ['nullable', 'string', 'max:255'],
            'shopifyClientSecret' => ['nullable', 'string', 'max:255'],
            'billbeeApiKey' => ['nullable', 'string', 'max:255'],
            'billbeeUser' => ['nullable', 'string', 'max:255'],
            'billbeePassword' => ['nullable', 'string', 'max:255'],
            'costMode' => ['required', 'in:net,gross'],
            'vatRate' => ['required', 'numeric', 'between:0,30'],
        ];
    }

    protected function messages(): array
    {
        return [
            'shopifyShop.regex' => 'Bitte die myshopify-Adresse angeben, z. B. johtomarket.myshopify.com.',
            'shopifyVersion.regex' => 'Format JJJJ-MM, z. B. 2026-10.',
        ];
    }

    public function save(): void
    {
        $this->shopifyShop = strtolower(trim(preg_replace('#^https?://#', '', $this->shopifyShop), '/ '));
        $this->validate();

        Setting::put('shopify.shop', $this->shopifyShop ?: null);
        Setting::put('shopify.api_version', $this->shopifyVersion);
        Setting::put('shopify.client_id', trim($this->shopifyClientId) ?: null);
        Setting::put('billbee.username', trim($this->billbeeUser) ?: null);
        Setting::put('billbee.cost_price_mode', $this->costMode);
        Setting::put('billbee.vat_rate', (string) (float) str_replace(',', '.', $this->vatRate));
        foreach (['shopify.token' => 'shopifyToken', 'shopify.client_secret' => 'shopifyClientSecret', 'billbee.api_key' => 'billbeeApiKey', 'billbee.password' => 'billbeePassword'] as $key => $prop) {
            if (trim($this->{$prop}) !== '') {
                Setting::put($key, trim($this->{$prop}));
            }
            $this->{$prop} = '';
        }
        $this->tests = [];
        $this->notice = 'Gespeichert.';
    }

    /** Festes Token entfernen, z. B. nach dem Umstieg auf eine App aus dem Dev Dashboard. */
    public function forgetShopifyToken(): void
    {
        Setting::put('shopify.token', null);
        $this->notice = 'Festes Token entfernt.';
    }

    public function testShopify(): void
    {
        try {
            $name = app(ShopifyClient::class)->shopName();
            $this->tests['shopify'] = ['ok' => true, 'text' => 'Verbunden mit „'.$name.'“.'];
        } catch (\Throwable $e) {
            $this->tests['shopify'] = ['ok' => false, 'text' => $e->getMessage()];
        }
    }

    public function testBillbee(): void
    {
        try {
            $res = app(BillbeeClient::class)->products(1, 1);
            $this->tests['billbee'] = ['ok' => true, 'text' => $res['data'] === [] ? 'Verbunden, aber keine Artikel gefunden.' : 'Verbunden, Artikel sind lesbar.'];
        } catch (\Throwable $e) {
            $this->tests['billbee'] = ['ok' => false, 'text' => $e->getMessage()];
        }
    }

    public function render()
    {
        return view('livewire.settings', [
            'hasShopifyToken' => filled(Setting::get('shopify.token')),
            'hasShopifySecret' => filled(Setting::get('shopify.client_secret')),
            'hasBillbeeKey' => filled(Setting::get('billbee.api_key')),
            'hasBillbeePassword' => filled(Setting::get('billbee.password')),
            'intervals' => config('marktschreier.sync'),
        ]);
    }
}
