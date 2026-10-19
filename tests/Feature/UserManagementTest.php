<?php

namespace Tests\Feature;

use App\Livewire\Login;
use App\Livewire\Settings\Account;
use App\Livewire\Settings\Users;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_staff_with_start_password_who_must_change_it(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $c = Livewire::test(Users::class)
            ->set('name', 'Lea')->set('email', 'LEA@example.de')->set('role', User::STAFF)
            ->call('create')->assertHasNoErrors();
        $password = $c->get('issuedPassword')['password'];
        $lea = User::where('email', 'lea@example.de')->sole();
        $this->assertSame(User::STAFF, $lea->role);
        $this->assertTrue($lea->must_change_password);
        $this->assertTrue(Hash::check($password, $lea->password));

        auth()->logout();
        Livewire::test(Login::class)->set('email', 'lea@example.de')->set('password', $password)->call('login')
            ->assertRedirect(route('settings.account'));
        $this->get('/artikel')->assertRedirect(route('settings.account'));

        Livewire::test(Account::class)->set('currentPassword', $password)
            ->set('newPassword', 'ein-gutes-passwort')->set('newPassword_confirmation', 'ein-gutes-passwort')
            ->call('changePassword')->assertHasNoErrors();
        $this->assertFalse($lea->fresh()->must_change_password);
        $this->get('/artikel')->assertOk();
    }

    public function test_staff_cannot_open_admin_settings(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::STAFF]));
        $this->get('/einstellungen')->assertForbidden();
        $this->get('/einstellungen/benutzer')->assertForbidden();
        $this->get('/einstellungen/ebay')->assertForbidden();
        $this->get('/einstellungen/konto')->assertOk();
        $this->get('/artikel')->assertOk()->assertSee('Mein Konto');
    }

    public function test_last_admin_is_protected_and_self_cannot_be_locked(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        Livewire::test(Users::class)->call('setRole', $admin->id, User::STAFF)->assertSet('notice', fn ($n) => str_contains($n, 'Mindestens ein'));
        Livewire::test(Users::class)->call('toggleActive', $admin->id)->assertSet('notice', fn ($n) => str_contains($n, 'eigenen Zugang'));
        $this->assertTrue($admin->fresh()->isAdmin());

        $second = User::factory()->create();
        Livewire::test(Users::class)->call('setRole', $admin->id, User::STAFF);
        $this->assertFalse($admin->fresh()->isAdmin());
        $this->assertTrue($second->fresh()->isAdmin());
    }

    public function test_locked_user_cannot_log_in_and_is_thrown_out(): void
    {
        $admin = User::factory()->create();
        $bob = User::factory()->create(['role' => User::STAFF, 'password' => 'bobs-passwort-123']);
        $this->actingAs($admin);
        Livewire::test(Users::class)->call('toggleActive', $bob->id);
        $this->assertFalse($bob->fresh()->active);

        auth()->logout();
        Livewire::test(Login::class)->set('email', $bob->email)->set('password', 'bobs-passwort-123')->call('login')->assertHasErrors('email');

        $this->actingAs($bob->fresh());
        $this->get('/artikel')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_reset_password_issues_new_start_password(): void
    {
        $admin = User::factory()->create();
        $bob = User::factory()->create(['role' => User::STAFF]);
        $this->actingAs($admin);
        $pw = Livewire::test(Users::class)->call('resetPassword', $bob->id)->get('issuedPassword')['password'];
        $this->assertTrue(Hash::check($pw, $bob->fresh()->password));
        $this->assertTrue($bob->fresh()->must_change_password);
    }

    public function test_account_rejects_wrong_current_password(): void
    {
        $this->actingAs(User::factory()->create(['password' => 'richtiges-passwort']));
        Livewire::test(Account::class)->set('currentPassword', 'falsch')
            ->set('newPassword', 'neues-passwort-123')->set('newPassword_confirmation', 'neues-passwort-123')
            ->call('changePassword')->assertHasErrors('currentPassword');
    }

    public function test_schedule_settings_are_used(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(\App\Livewire\Settings\Schedule::class)
            ->set('jobs.billbee.minutes', 30)->set('jobs.orders.enabled', false)->call('save');
        $this->assertSame(30, \App\Livewire\Settings\Schedule::interval('billbee'));
        $this->assertFalse(\App\Livewire\Settings\Schedule::enabled('orders'));
    }
}
