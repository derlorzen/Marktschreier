<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.guest')]
class Login extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required')]
    public string $password = '';

    public bool $remember = true;

    public function login()
    {
        $this->validate();
        $key = Str::lower($this->email).'|'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Zu viele Versuche. Bitte in '.RateLimiter::availableIn($key).' Sekunden erneut versuchen.']);
        }
        $credentials = ['email' => mb_strtolower(trim($this->email)), 'password' => $this->password, 'active' => true];
        if (! Auth::validate($credentials)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'E-Mail oder Passwort stimmt nicht.']);
        }
        RateLimiter::clear($key);
        $user = \App\Models\User::where('email', $credentials['email'])->first();
        if ($user->hasTwoFactor()) {
            // Passwort stimmt, angemeldet wird erst nach dem Code.
            session()->put('2fa', ['id' => $user->id, 'remember' => $this->remember, 'at' => time()]);

            return $this->redirect(route('login.two-factor'));
        }
        Auth::login($user, $this->remember);
        session()->regenerate();
        Auth::user()->forceFill(['last_login_at' => now()])->save();
        if (Auth::user()->must_change_password) {
            return $this->redirect(route('settings.account'));
        }

        return $this->redirectIntended(route('dashboard'), navigate: false);
    }

    public function render()
    {
        return view('livewire.login', ['mailConfigured' => ! in_array(config('mail.default'), ['log', 'array'], true)]);
    }
}
