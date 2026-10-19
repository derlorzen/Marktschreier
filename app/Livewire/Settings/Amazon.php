<?php

namespace App\Livewire\Settings;

use App\Models\MarketplaceConnection;
use App\Services\Marketplaces\Amazon\AmazonService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Amazon')]
class Amazon extends Component
{
    public string $fulfillment = 'FBM';

    public string $manualSellerId = '';

    public string $manualRefreshToken = '';

    public ?string $notice = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->fulfillment = (string) MarketplaceConnection::for(MarketplaceConnection::AMAZON)->setting('fulfillment', 'FBM');
        $this->notice = session('flash');
        $this->error = session('flash_error');
    }

    public function save(): void
    {
        $this->validate(['fulfillment' => ['in:FBM,FBA']]);
        MarketplaceConnection::for(MarketplaceConnection::AMAZON)->putSettings(['fulfillment' => $this->fulfillment]);
        $this->notice = 'Gespeichert.';
    }

    /** Selbst autorisierte App: Refresh-Token und Verkäufer-ID aus Seller Central einfügen. */
    public function connectManually(AmazonService $amazon): void
    {
        $this->validate([
            'manualSellerId' => ['required', 'regex:/^A[0-9A-Z]{8,20}$/'],
            'manualRefreshToken' => ['required', 'string', 'starts_with:Atzr|', 'max:2000'],
        ], [
            'manualSellerId.regex' => 'Die Verkäufer-ID beginnt mit A, z. B. A2EXAMPLE1234.',
            'manualRefreshToken.starts_with' => 'Ein Refresh-Token beginnt mit „Atzr|“.',
        ]);
        try {
            $amazon->client()->connectWithRefreshToken(trim($this->manualRefreshToken), trim($this->manualSellerId));
            $name = $amazon->refreshAccountName();
            $this->reset('manualRefreshToken', 'manualSellerId');
            $this->notice = 'Mit Amazon verbunden'.($name ? ": {$name}" : '').'.';
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function test(AmazonService $amazon): void
    {
        try {
            $this->notice = 'Verbindung steht: '.$amazon->refreshAccountName().'.';
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function disconnect(): void
    {
        MarketplaceConnection::for(MarketplaceConnection::AMAZON)->disconnect();
        $this->notice = 'Verbindung zu Amazon getrennt. Angebote bei Amazon bleiben unverändert.';
    }

    public function render(AmazonService $amazon)
    {
        return view('livewire.settings.amazon', [
            'connection' => MarketplaceConnection::for(MarketplaceConnection::AMAZON),
            'appConfigured' => $amazon->client()->appConfigured(),
            'websiteAuth' => $amazon->client()->canAuthorizeViaWebsite(),
            'callbackUrl' => route('settings.amazon.callback'),
            'listingCount' => \App\Models\Listing::where('channel_id', $amazon->channel()->id)->where('status', 'active')->count(),
        ]);
    }
}
