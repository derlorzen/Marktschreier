<?php

namespace App\Livewire\Settings;

use App\Models\Setting;
use App\Support\Format;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Allgemein')]
class General extends Component
{
    public string $company = '';

    public string $vatRate = '19';

    public string $minProfit = '';

    public bool $require2fa = false;

    public ?string $notice = null;

    public string $aiKey = '';

    public string $aiModel = '';

    public function mount(): void
    {
        $this->require2fa = Setting::get('security.require_2fa_admins', '0') === '1';
        $this->company = (string) Setting::get('general.company');
        $this->vatRate = Format::decimalForInput(Setting::get('billbee.vat_rate', 19));
        $this->minProfit = Format::decimalForInput(Setting::get('opportunities.min_profit', 1));
        $this->aiModel = (string) Setting::get('ai.model', 'claude-sonnet-5-5');
    }

    public function save(): void
    {
        $this->validate([
            'company' => ['nullable', 'string', 'max:80'],
            'vatRate' => ['required', function ($a, $v, $fail) {
                $n = Format::parseDecimal($v);
                if ($n === null || $n < 0 || $n > 30) {
                    $fail('Bitte einen Steuersatz zwischen 0 und 30 eingeben.');
                }
            }],
            'minProfit' => ['required', function ($a, $v, $fail) {
                if (Format::parseDecimal($v) === null) {
                    $fail('Bitte einen Betrag eingeben, z. B. 1,00.');
                }
            }],
        ]);
        Setting::put('general.company', trim($this->company) ?: null);
        Setting::put('billbee.vat_rate', (string) (float) Format::parseDecimal($this->vatRate));
        Setting::put('opportunities.min_profit', (string) (float) Format::parseDecimal($this->minProfit));
        Setting::put('ai.model', trim($this->aiModel) ?: null);
        if (trim($this->aiKey) !== '') {
            Setting::put('ai.api_key', trim($this->aiKey));
        }
        $this->aiKey = '';
        Setting::put('security.require_2fa_admins', $this->require2fa ? '1' : '0');
        $this->notice = 'Gespeichert.'.($this->require2fa && ! auth()->user()->hasTwoFactor() ? ' Bitte jetzt unter „Mein Konto“ die Zwei-Faktor-Anmeldung einrichten.' : '');
    }

    public function render()
    {
        return view('livewire.settings.general', ['hasAiKey' => filled(Setting::get('ai.api_key'))]);
    }
}
