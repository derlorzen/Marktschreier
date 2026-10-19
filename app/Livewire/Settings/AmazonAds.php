<?php

namespace App\Livewire\Settings;

use App\Services\Ads\AdsService;
use App\Support\Format;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Amazon Werbung')]
class AmazonAds extends Component
{
    public string $profileId = '';

    public string $targetAcos = '25';

    public ?string $notice = null;

    public ?string $error = null;

    public function mount(AdsService $ads): void
    {
        $c = $ads->client()->connection();
        $this->profileId = (string) $c->setting('profile_id');
        $this->targetAcos = Format::decimalForInput($c->setting('target_acos', 25));
        $this->notice = session('flash');
        $this->error = session('flash_error');
    }

    public function loadProfiles(AdsService $ads): void
    {
        try {
            $pick = $ads->autoSelectProfile();
            $this->profileId = (string) ($pick['id'] ?? $this->profileId);
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function save(AdsService $ads): void
    {
        $acos = Format::parseDecimal($this->targetAcos);
        if ($acos === null || $acos < 1 || $acos > 100) {
            $this->addError('targetAcos', 'Bitte einen Wert zwischen 1 und 100 eingeben.');

            return;
        }
        $c = $ads->client()->connection();
        $profiles = collect($c->setting('profiles', []));
        $c->putSettings(['profile_id' => $this->profileId ?: null, 'target_acos' => (float) $acos]);
        if ($p = $profiles->firstWhere('id', $this->profileId)) {
            $c->update(['account_name' => $p['name'].' ('.$p['country'].')']);
        }
        $this->notice = 'Gespeichert.';
    }

    public function disconnect(AdsService $ads): void
    {
        $ads->client()->connection()->disconnect();
        $this->notice = 'Verbindung getrennt. Kampagnen bei Amazon laufen unverändert weiter.';
    }

    public function render(AdsService $ads)
    {
        $c = $ads->client()->connection();

        return view('livewire.settings.amazon-ads', [
            'connection' => $c,
            'appConfigured' => $ads->client()->appConfigured(),
            'profiles' => $c->setting('profiles', []),
            'callbackUrl' => route('settings.ads.callback'),
        ]);
    }
}
