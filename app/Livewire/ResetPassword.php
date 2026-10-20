<?php

namespace App\Livewire;

use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.guest')]
class ResetPassword extends Component
{
    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = (string) request()->query('email', '');
    }

    public function save()
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ], ['password.min' => 'Das Passwort braucht mindestens 10 Zeichen.', 'password.confirmed' => 'Die Wiederholung passt nicht.']);

        $status = Password::broker()->reset(
            ['email' => mb_strtolower(trim($this->email)), 'password' => $this->password, 'password_confirmation' => $this->password_confirmation, 'token' => $this->token],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'must_change_password' => false, 'remember_token' => Str::random(60)])->save();
            }
        );
        if ($status !== Password::PASSWORD_RESET) {
            $this->addError('email', 'Der Link ist abgelaufen oder passt nicht zu dieser Adresse. Bitte einen neuen anfordern.');

            return;
        }
        session()->flash('flash_ok', 'Passwort geändert. Du kannst dich jetzt anmelden.');

        return $this->redirect(route('login'));
    }

    public function render()
    {
        return view('livewire.reset-password');
    }
}
