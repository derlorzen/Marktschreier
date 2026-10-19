<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'active', 'must_change_password', 'last_login_at', 'mail_alerts', 'mail_weekly'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ADMIN = 'admin';
    public const STAFF = 'staff';

    public const ROLES = [
        self::ADMIN => 'Administrator',
        self::STAFF => 'Mitarbeiter',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'must_change_password' => 'boolean',
            'mail_alerts' => 'boolean',
            'mail_weekly' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ADMIN;
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }

    /** Der letzte aktive Administrator darf weder gesperrt noch herabgestuft werden. */
    public function isLastAdmin(): bool
    {
        return $this->isAdmin() && $this->active
            && self::where('role', self::ADMIN)->where('active', true)->whereKeyNot($this->id)->doesntExist();
    }
}
