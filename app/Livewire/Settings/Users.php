<?php

namespace App\Livewire\Settings;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Benutzer')]
class Users extends Component
{
    public bool $showForm = false;

    public string $name = '';

    public string $email = '';

    public string $role = User::STAFF;

    public ?string $notice = null;

    /** Startpasswort, nur einmal angezeigt */
    public ?array $issuedPassword = null;

    public static function generatePassword(): string
    {
        // Gut abtippbar: ohne 0/O, 1/l/I
        $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 14; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return implode('-', str_split($out, 7));
    }

    public function create(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
        ], ['email.unique' => 'Für diese E-Mail-Adresse gibt es schon einen Zugang.']);

        $password = self::generatePassword();
        $user = User::create([
            'name' => trim($this->name), 'email' => mb_strtolower(trim($this->email)), 'role' => $this->role,
            'password' => $password, 'active' => true, 'must_change_password' => true,
        ]);
        $this->issuedPassword = ['name' => $user->name, 'email' => $user->email, 'password' => $password];
        $this->reset('name', 'email', 'showForm');
        $this->role = User::STAFF;
    }

    public function setRole(int $id, string $role): void
    {
        $user = User::findOrFail($id);
        if (! array_key_exists($role, User::ROLES)) {
            return;
        }
        if ($role !== User::ADMIN && $user->isLastAdmin()) {
            $this->notice = 'Mindestens ein aktiver Administrator muss bleiben.';

            return;
        }
        $user->update(['role' => $role]);
        $this->notice = "{$user->name} ist jetzt {$user->roleLabel()}.";
    }

    public function toggleActive(int $id): void
    {
        $user = User::findOrFail($id);
        if ($user->id === auth()->id()) {
            $this->notice = 'Den eigenen Zugang kannst du nicht sperren.';

            return;
        }
        if ($user->active && $user->isLastAdmin()) {
            $this->notice = 'Mindestens ein aktiver Administrator muss bleiben.';

            return;
        }
        $user->update(['active' => ! $user->active]);
        if (! $user->active) {
            // Angemeldete Sitzungen sofort beenden.
            if (config('session.driver') === 'database') {
                \Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            $user->forceFill(['remember_token' => Str::random(60)])->save();
        }
        $this->notice = $user->active ? "{$user->name} ist wieder freigeschaltet." : "{$user->name} ist gesperrt und wurde abgemeldet.";
    }

    public function resetTwoFactor(int $id): void
    {
        $user = User::findOrFail($id);
        app(\App\Services\Auth\TwoFactor::class)->disable($user);
        $this->notice = "Zwei-Faktor für {$user->name} zurückgesetzt. Beim nächsten Anmelden reicht das Passwort, danach neu einrichten.";
    }

    public function resetPassword(int $id): void
    {
        $user = User::findOrFail($id);
        $password = self::generatePassword();
        $user->forceFill(['password' => $password, 'must_change_password' => true, 'remember_token' => Str::random(60)])->save();
        $this->issuedPassword = ['name' => $user->name, 'email' => $user->email, 'password' => $password];
    }

    public function render()
    {
        return view('livewire.settings.users', [
            'users' => User::orderByDesc('active')->orderBy('name')->get(),
        ]);
    }
}
