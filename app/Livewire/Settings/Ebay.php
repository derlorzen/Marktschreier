<?php

namespace App\Livewire\Settings;

use App\Models\MarketplaceConnection;
use App\Services\Marketplaces\Ebay\EbayService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('eBay')]
class Ebay extends Component
{
    public string $fulfillmentPolicy = '';

    public string $paymentPolicy = '';

    public string $returnPolicy = '';

    public string $postalCode = '';

    public string $location = '';

    public string $condition = '1000';

    /** @var array{fulfillment: array, payment: array, return: array}|null */
    public ?array $policies = null;

    public ?string $notice = null;

    public ?string $error = null;

    public function mount(): void
    {
        $c = MarketplaceConnection::for(MarketplaceConnection::EBAY);
        $this->fulfillmentPolicy = (string) $c->setting('fulfillment_policy');
        $this->paymentPolicy = (string) $c->setting('payment_policy');
        $this->returnPolicy = (string) $c->setting('return_policy');
        $this->postalCode = (string) $c->setting('postal_code');
        $this->location = (string) $c->setting('location');
        $this->condition = (string) $c->setting('condition', 1000);
        $this->policies = $c->setting('policies_cache');
        if (session('flash')) {
            $this->notice = session('flash');
        }
        if (session('flash_error')) {
            $this->error = session('flash_error');
        }
    }

    public function loadPolicies(EbayService $ebay): void
    {
        try {
            $this->policies = $ebay->policies();
            MarketplaceConnection::for(MarketplaceConnection::EBAY)->putSettings(['policies_cache' => $this->policies]);
            $this->error = null;
            foreach (['fulfillment' => 'fulfillmentPolicy', 'payment' => 'paymentPolicy', 'return' => 'returnPolicy'] as $type => $prop) {
                if ($this->{$prop} === '' && count($this->policies[$type]) === 1) {
                    $this->{$prop} = (string) array_key_first($this->policies[$type]);
                }
            }
        } catch (\Throwable $e) {
            $this->error = $e->getMessage().' Richtlinien legst du in eBay unter Konto, Geschäftsrichtlinien an.';
        }
    }

    public function save(): void
    {
        $this->validate([
            'postalCode' => ['nullable', 'regex:/^\d{5}$/'],
            'location' => ['nullable', 'string', 'max:45'],
            'condition' => ['in:1000,1500,3000'],
        ], ['postalCode.regex' => 'Bitte eine fünfstellige Postleitzahl eingeben.']);
        MarketplaceConnection::for(MarketplaceConnection::EBAY)->putSettings([
            'fulfillment_policy' => $this->fulfillmentPolicy ?: null,
            'payment_policy' => $this->paymentPolicy ?: null,
            'return_policy' => $this->returnPolicy ?: null,
            'postal_code' => $this->postalCode ?: null,
            'location' => trim($this->location) ?: null,
            'condition' => (int) $this->condition,
        ]);
        $this->notice = 'Gespeichert.';
    }

    public function disconnect(): void
    {
        MarketplaceConnection::for(MarketplaceConnection::EBAY)->disconnect();
        $this->notice = 'Verbindung zu eBay getrennt. Angebote bei eBay bleiben unverändert.';
    }

    public function render(EbayService $ebay)
    {
        $c = MarketplaceConnection::for(MarketplaceConnection::EBAY);

        return view('livewire.settings.ebay', [
            'connection' => $c,
            'appConfigured' => $ebay->client()->appConfigured(),
            'listingCount' => \App\Models\Listing::where('channel_id', $ebay->channel()->id)->where('status', 'active')->count(),
            'conditions' => EbayService::CONDITIONS,
            'callbackUrl' => route('settings.ebay.callback'),
        ]);
    }
}
