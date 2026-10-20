<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.guest')]
class ForgotPassword extends Component
{
    public string $email = '';

    public bool $sent = false;

    public function send(): void
    {
        $this->validate(['email' => ['required', 'email']], ['email.required' => 'Bitte die E-Mail-Adresse eingeben.', 'email.email' => 'Bitte eine gültige E-Mail-Adresse eingeben.']);
        // Antwort immer gleich, damit niemand herausfinden kann, welche Adressen es gibt.
        Password::broker()->sendResetLink(['email' => mb_strtolower(trim($this->email)), 'active' => true]);
        $this->sent = true;
    }

    public function render()
    {
        return view('livewire.forgot-password');
    }
}
