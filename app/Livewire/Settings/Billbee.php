<?php

namespace App\Livewire\Settings;

use App\Models\Setting;
use App\Models\SyncRun;
use App\Services\Billbee\BillbeeClient;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Billbee')]
class Billbee extends Component
{
    public string $billbeeApiKey = '';

    public string $billbeeUser = '';

    public string $billbeePassword = '';

    public string $costMode = 'net';

    public ?array $test = null;

    public ?string $notice = null;

    public function mount(): void
    {
        $this->billbeeUser = (string) Setting::get('billbee.username');
        $this->costMode = (string) Setting::get('billbee.cost_price_mode', 'net');
    }

    public function save(): void
    {
        $this->validate([
            'billbeeApiKey' => ['nullable', 'string', 'max:255'],
            'billbeeUser' => ['nullable', 'string', 'max:255'],
            'billbeePassword' => ['nullable', 'string', 'max:255'],
            'costMode' => ['required', 'in:net,gross'],
        ]);
        Setting::put('billbee.username', trim($this->billbeeUser) ?: null);
        Setting::put('billbee.cost_price_mode', $this->costMode);
        foreach (['billbee.api_key' => 'billbeeApiKey', 'billbee.password' => 'billbeePassword'] as $key => $prop) {
            if (trim($this->{$prop}) !== '') {
                Setting::put($key, trim($this->{$prop}));
            }
            $this->{$prop} = '';
        }
        $this->test = null;
        $this->notice = 'Gespeichert.';
    }

    public function testConnection(): void
    {
        try {
            $res = app(BillbeeClient::class)->products(1, 1);
            $this->test = ['ok' => true, 'text' => $res['data'] === [] ? 'Verbunden, aber keine Artikel gefunden.' : 'Verbunden, Artikel sind lesbar.'];
        } catch (\Throwable $e) {
            $this->test = ['ok' => false, 'text' => $e->getMessage()];
        }
    }

    public function render()
    {
        return view('livewire.settings.billbee', [
            'hasKey' => filled(Setting::get('billbee.api_key')),
            'hasPassword' => filled(Setting::get('billbee.password')),
            'lastStock' => SyncRun::lastSuccessful(SyncRun::TYPE_BILLBEE),
            'lastOrders' => SyncRun::lastSuccessful(SyncRun::TYPE_BILLBEE_ORDERS),
        ]);
    }
}
