<?php

namespace App\Livewire\Settings;

use App\Jobs\SyncPlenty;
use App\Models\Setting;
use App\Models\SyncRun;
use App\Services\Plenty\PlentyClient;
use App\Services\Plenty\PlentyImporter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('PlentyONE')]
class Plenty extends Component
{
    public string $url = '';

    public string $username = '';

    public string $password = '';

    public string $salesPriceId = '1';

    public string $warehouseIds = '';

    public string $lang = 'de';

    public ?array $test = null;

    public ?array $priceTypes = null;

    public ?string $notice = null;

    public function mount(): void
    {
        $this->url = (string) Setting::get('plenty.url');
        $this->username = (string) Setting::get('plenty.username');
        $this->salesPriceId = (string) Setting::get('plenty.sales_price_id', 1);
        $this->warehouseIds = (string) Setting::get('plenty.warehouse_ids');
        $this->lang = (string) Setting::get('plenty.lang', 'de');
    }

    public function save(): void
    {
        $this->url = rtrim(trim($this->url), '/');
        $this->validate([
            'url' => ['nullable', 'url:https'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'salesPriceId' => ['required', 'integer', 'min:1'],
            'warehouseIds' => ['nullable', 'regex:/^\s*\d+(\s*,\s*\d+)*\s*$/'],
            'lang' => ['required', 'regex:/^[a-z]{2}$/'],
        ], ['url.url' => 'Bitte die Adresse des Systems mit https:// angeben, z. B. https://p2241.my.plentysystems.com.', 'warehouseIds.regex' => 'Lager-IDs mit Komma trennen, z. B. 1, 3.']);
        Setting::put('plenty.url', $this->url ?: null);
        Setting::put('plenty.username', trim($this->username) ?: null);
        if (trim($this->password) !== '') {
            Setting::put('plenty.password', trim($this->password));
        }
        $this->password = '';
        Setting::put('plenty.sales_price_id', $this->salesPriceId);
        Setting::put('plenty.warehouse_ids', preg_replace('/\s+/', '', $this->warehouseIds) ?: null);
        Setting::put('plenty.lang', $this->lang);
        $this->notice = 'Gespeichert.';
        $this->test = null;
    }

    public function testConnection(): void
    {
        try {
            $client = new PlentyClient;
            $types = $client->get('items/sales_prices', ['itemsPerPage' => 50, 'with' => 'names']);
            $this->priceTypes = collect($types['entries'] ?? $types)->mapWithKeys(fn ($p) => [$p['id'] => ($p['names'][0]['nameInternal'] ?? $p['names'][0]['nameExternal'] ?? 'Preis '.$p['id'])])->all();
            $this->test = ['ok' => true, 'text' => 'Verbunden. '.count($this->priceTypes).' Verkaufspreise gefunden.'];
        } catch (\Throwable $e) {
            $this->test = ['ok' => false, 'text' => $e->getMessage()];
        }
    }

    public function start(string $what, bool $full = false): void
    {
        $type = $what === 'orders' ? PlentyImporter::TYPE_ORDERS : PlentyImporter::TYPE_ITEMS;
        $run = SyncRun::create(['type' => $type, 'status' => 'queued', 'stats' => []]);
        SyncPlenty::dispatch($what, $full, $run->id);
        $this->notice = $what === 'orders' ? 'Aufträge werden eingelesen.' : 'Artikel werden eingelesen. Den Fortschritt siehst du unter Abgleich.';
    }

    public function render()
    {
        return view('livewire.settings.plenty', [
            'configured' => (new PlentyClient)->isConfigured(),
            'hasPassword' => filled(Setting::get('plenty.password')),
            'lastItems' => SyncRun::lastSuccessful(PlentyImporter::TYPE_ITEMS),
            'lastOrders' => SyncRun::lastSuccessful(PlentyImporter::TYPE_ORDERS),
            'count' => \App\Models\Product::whereNotNull('plenty_id')->count(),
        ]);
    }
}
