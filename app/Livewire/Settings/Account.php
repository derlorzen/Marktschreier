<?php

namespace App\Livewire\Settings;

use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Mein Konto')]
class Account extends Component
{
    public string $name = '';

    public string $email = '';

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPassword_confirmation = '';

    public ?string $notice = null;

    /** Zwei-Faktor einrichten: Geheimnis nur bis zur Bestätigung im Komponentenzustand (verschlüsselt). */
    public ?string $pendingSecret = null;

    public string $twoFactorCode = '';

    public string $twoFactorPassword = '';

    /** @var list<string>|null nur direkt nach dem Einrichten sichtbar */
    public ?array $recoveryCodes = null;

    public function startTwoFactor(\App\Services\Auth\TwoFactor $tf): void
    {
        $this->pendingSecret = encrypt($tf->newSecret());
        $this->twoFactorCode = '';
    }

    public function confirmTwoFactor(\App\Services\Auth\TwoFactor $tf): void
    {
        $this->resetErrorBag();
        if (! $this->pendingSecret) {
            return;
        }
        $secret = decrypt($this->pendingSecret);
        if (! $tf->verify($secret, $this->twoFactorCode)) {
            $this->addError('twoFactorCode', 'Der Code stimmt nicht. Uhrzeit am Handy prüfen und den aktuellen Code eingeben.');

            return;
        }
        $this->recoveryCodes = $tf->enable(auth()->user(), $secret);
        $this->pendingSecret = null;
        $this->twoFactorCode = '';
        $this->notice = 'Zwei-Faktor-Anmeldung ist eingeschaltet.';
    }

    public function disableTwoFactor(\App\Services\Auth\TwoFactor $tf): void
    {
        $this->resetErrorBag();
        if (! \Illuminate\Support\Facades\Hash::check($this->twoFactorPassword, auth()->user()->password)) {
            $this->addError('twoFactorPassword', 'Das Passwort stimmt nicht.');

            return;
        }
        if (\App\Services\Auth\TwoFactor::requiredFor(auth()->user())) {
            $this->addError('twoFactorPassword', 'Für Administratoren ist die Zwei-Faktor-Anmeldung vorgeschrieben.');

            return;
        }
        $tf->disable(auth()->user());
        $this->twoFactorPassword = '';
        $this->notice = 'Zwei-Faktor-Anmeldung ist ausgeschaltet.';
    }

    public bool $mailAlerts = true;

    public bool $mailWeekly = true;

    public bool $mailMonthly = false;

    public function mount(): void
    {
        $this->name = auth()->user()->name;
        $this->email = auth()->user()->email;
        $this->mailAlerts = (bool) (auth()->user()->mail_alerts ?? true);
        $this->mailWeekly = (bool) (auth()->user()->mail_weekly ?? true);
        $this->mailMonthly = (bool) (auth()->user()->mail_monthly ?? false);
    }

    public function saveProfile(): void
    {
        $user = auth()->user();
        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ], ['email.unique' => 'Diese E-Mail-Adresse wird schon verwendet.']);
        $user->update(['name' => trim($this->name), 'email' => mb_strtolower(trim($this->email))]);
        $this->notice = 'Gespeichert.';
    }

    public function saveMailPrefs(): void
    {
        auth()->user()->update(['mail_alerts' => $this->mailAlerts, 'mail_weekly' => $this->mailWeekly, 'mail_monthly' => $this->mailMonthly]);
        $this->notice = 'Mail-Einstellungen gespeichert.';
    }

    public function changePassword(): void
    {
        $user = auth()->user();
        $this->validate([
            'currentPassword' => ['required'],
            'newPassword' => ['required', 'string', 'min:10', 'confirmed', 'different:currentPassword'],
        ], [
            'newPassword.min' => 'Das neue Passwort braucht mindestens 10 Zeichen.',
            'newPassword.confirmed' => 'Die Wiederholung passt nicht.',
            'newPassword.different' => 'Das neue Passwort muss sich vom bisherigen unterscheiden.',
        ]);
        if (! Hash::check($this->currentPassword, $user->password)) {
            $this->addError('currentPassword', 'Das bisherige Passwort stimmt nicht.');

            return;
        }
        $user->forceFill(['password' => $this->newPassword, 'must_change_password' => false])->save();
        $this->reset('currentPassword', 'newPassword', 'newPassword_confirmation');
        $this->notice = 'Passwort geändert.';
    }

    public function render()
    {
        return view('livewire.settings.account', [
            'qr' => $this->pendingSecret ? app(\App\Services\Auth\TwoFactor::class)->qrSvg(auth()->user(), decrypt($this->pendingSecret)) : null,
            'secretText' => $this->pendingSecret ? trim(chunk_split(decrypt($this->pendingSecret), 4, ' ')) : null,
            'twoFactorRequired' => \App\Services\Auth\TwoFactor::requiredFor(auth()->user()),'user' => auth()->user()]);
    }
}
