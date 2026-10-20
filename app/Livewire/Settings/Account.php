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
        return view('livewire.settings.account', ['user' => auth()->user()]);
    }
}
