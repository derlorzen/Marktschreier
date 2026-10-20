<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\Auth\TwoFactor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.guest')]
class TwoFactorChallenge extends Component
{
    public string $code = '';

    public function mount()
    {
        $pending = session('2fa');
        if (! $pending || time() - $pending['at'] > 600) {
            session()->forget('2fa');

            return $this->redirect(route('login'));
        }
    }

    public function verify(TwoFactor $twoFactor)
    {
        $this->resetErrorBag();
        $pending = session('2fa');
        $user = $pending ? User::where('active', true)->find($pending['id']) : null;
        if (! $user || time() - $pending['at'] > 600) {
            session()->forget('2fa');

            return $this->redirect(route('login'));
        }
        $key = '2fa|'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('code', 'Zu viele Versuche. Bitte in '.RateLimiter::availableIn($key).' Sekunden erneut versuchen.');

            return;
        }
        if (! $twoFactor->check($user, $this->code)) {
            RateLimiter::hit($key, 120);
            $this->addError('code', 'Der Code stimmt nicht.');

            return;
        }
        RateLimiter::clear($key);
        session()->forget('2fa');
        Auth::login($user, (bool) $pending['remember']);
        session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return $user->must_change_password
            ? $this->redirect(route('settings.account'))
            : $this->redirectIntended(route('dashboard'), navigate: false);
    }

    public function render()
    {
        return view('livewire.two-factor-challenge');
    }
}
