<?php

namespace Tests\Feature;

use App\Livewire\ForgotPassword;
use App\Livewire\Login;
use App\Livewire\ResetPassword;
use App\Livewire\Settings\Account;
use App\Livewire\TwoFactorChallenge;
use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\TwoFactor;
use Illuminate\Auth\Notifications\ResetPassword as ResetNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AuthExtrasTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_by_mail(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'lea@example.de', 'must_change_password' => true]);

        Livewire::test(ForgotPassword::class)->set('email', 'Lea@Example.de')->call('send')->assertSet('sent', true);
        Livewire::test(ForgotPassword::class)->set('email', 'gibtsnicht@example.de')->call('send')->assertSet('sent', true);

        $token = null;
        Notification::assertSentTo($user, ResetNotification::class, function ($n) use (&$token, $user) {
            $token = $n->token;
            $mail = $n->toMail($user);

            return $mail->subject === 'Marktschreier: Neues Passwort festlegen';
        });

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))->assertOk()->assertSee('Neues Passwort');
        Livewire::test(ResetPassword::class, ['token' => $token])
            ->set('email', 'lea@example.de')->set('password', 'ganz-neues-passwort')->set('password_confirmation', 'ganz-neues-passwort')
            ->call('save')->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('ganz-neues-passwort', $user->fresh()->password));
        $this->assertFalse($user->fresh()->must_change_password);

        Livewire::test(ResetPassword::class, ['token' => 'falsch'])
            ->set('email', 'lea@example.de')->set('password', 'noch-ein-passwort')->set('password_confirmation', 'noch-ein-passwort')
            ->call('save')->assertHasErrors('email');
    }

    public function test_locked_user_gets_no_reset_mail(): void
    {
        Notification::fake();
        $user = User::factory()->create(['active' => false]);
        Livewire::test(ForgotPassword::class)->set('email', $user->email)->call('send');
        Notification::assertNothingSent();
    }

    public function test_two_factor_setup_login_and_recovery(): void
    {
        $user = User::factory()->create(['password' => 'mein-passwort-123']);
        $this->actingAs($user);
        $g = new Google2FA;

        $c = Livewire::test(Account::class)->call('startTwoFactor')->assertSee('QR-Code');
        $secret = decrypt($c->get('pendingSecret'));
        $c->set('twoFactorCode', '000000')->call('confirmTwoFactor')->assertHasErrors('twoFactorCode');
        $c->set('twoFactorCode', $g->getCurrentOtp($secret))->call('confirmTwoFactor')->assertHasNoErrors();
        $codes = $c->get('recoveryCodes');
        $this->assertCount(8, $codes);
        $this->assertTrue($user->fresh()->hasTwoFactor());
        $this->assertStringNotContainsString($secret, (string) $user->fresh()->getRawOriginal('two_factor_secret'));

        auth()->logout();
        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'mein-passwort-123')->call('login')
            ->assertRedirect(route('login.two-factor'));
        $this->assertGuest();

        Livewire::test(TwoFactorChallenge::class)->set('code', '111111')->call('verify')->assertHasErrors('code');
        $this->assertGuest();
        Livewire::test(TwoFactorChallenge::class)->set('code', $codes[0])->call('verify')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        // Wiederherstellungscode ist verbraucht
        $this->assertFalse(app(TwoFactor::class)->check($user->fresh(), $codes[0]));
        $this->assertTrue(app(TwoFactor::class)->check($user->fresh(), $g->getCurrentOtp($secret)));
    }

    public function test_two_factor_can_be_required_for_admins(): void
    {
        $admin = User::factory()->create();
        $staff = User::factory()->create(['role' => User::STAFF]);
        Setting::put('security.require_2fa_admins', '1');

        $this->actingAs($admin)->get('/artikel')->assertRedirect(route('settings.account'));
        $this->actingAs($staff)->get('/artikel')->assertOk();
        $this->actingAs($admin)->get('/einstellungen/konto')->assertOk()->assertSee('vorgeschrieben');
    }

    public function test_admin_can_reset_two_factor_of_others(): void
    {
        $admin = User::factory()->create();
        $bob = User::factory()->create(['role' => User::STAFF]);
        app(TwoFactor::class)->enable($bob, (new Google2FA)->generateSecretKey());
        $this->actingAs($admin);
        Livewire::test(\App\Livewire\Settings\Users::class)->assertSee('2FA')->call('resetTwoFactor', $bob->id);
        $this->assertFalse($bob->fresh()->hasTwoFactor());
    }
}
