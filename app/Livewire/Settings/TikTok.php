<?php

namespace App\Livewire\Settings;

use App\Models\Listing;
use App\Models\MarketplaceConnection;
use App\Services\Marketplaces\TikTok\TikTokService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('TikTok Shop')]
class TikTok extends Component
{
    public ?string $notice = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->notice = session('flash');
        $this->error = session('flash_error');
    }

    public function disconnect(): void
    {
        MarketplaceConnection::for(MarketplaceConnection::TIKTOK)->disconnect();
        $this->notice = 'Verbindung zu TikTok Shop getrennt. Die Angebote dort bleiben unverändert.';
    }

    public function render(TikTokService $tiktok)
    {
        return view('livewire.settings.tiktok', [
            'connection' => MarketplaceConnection::for(MarketplaceConnection::TIKTOK),
            'appConfigured' => $tiktok->client()->appConfigured(),
            'callbackUrl' => route('settings.tiktok.callback'),
            'listingCount' => Listing::where('channel_id', $tiktok->channel()->id)->where('status', 'active')->count(),
        ]);
    }
}
