<?php

namespace App\Services\Auth;

use App\Models\Setting;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/** Zwei-Faktor-Anmeldung mit Authenticator-App (TOTP) und Wiederherstellungscodes. */
class TwoFactor
{
    public function __construct(private Google2FA $g2fa = new Google2FA)
    {
    }

    public function newSecret(): string
    {
        return $this->g2fa->generateSecretKey(32);
    }

    public function qrSvg(User $user, string $secret): string
    {
        $issuer = 'Marktschreier'.(($c = Setting::get('general.company')) ? ' '.$c : '');
        $url = $this->g2fa->getQRCodeUrl($issuer, $user->email, $secret);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(180, 1), new SvgImageBackEnd)))->writeString($url);

        return trim(preg_replace('/^<\?xml.*?\?>/', '', $svg));
    }

    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);

        return preg_match('/^\d{6}$/', $code) === 1 && $this->g2fa->verifyKey($secret, $code, 1);
    }

    /** @return list<string> */
    public function newRecoveryCodes(): array
    {
        return collect(range(1, 8))->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))->all();
    }

    public function enable(User $user, string $secret): array
    {
        $codes = $this->newRecoveryCodes();
        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode($codes)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $codes;
    }

    public function disable(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
    }

    /** Prüft Code aus der App oder einen Wiederherstellungscode (der danach verbraucht ist). */
    public function check(User $user, string $input): bool
    {
        if (! $user->hasTwoFactor()) {
            return false;
        }
        $input = trim($input);
        if ($this->verify(decrypt($user->two_factor_secret), $input)) {
            return true;
        }
        $codes = json_decode(decrypt($user->two_factor_recovery_codes), true) ?: [];
        $normalized = Str::lower($input);
        if (in_array($normalized, $codes, true)) {
            $user->forceFill(['two_factor_recovery_codes' => encrypt(json_encode(array_values(array_diff($codes, [$normalized]))))])->save();

            return true;
        }

        return false;
    }

    public static function requiredFor(User $user): bool
    {
        return $user->isAdmin() && Setting::get('security.require_2fa_admins', '0') === '1';
    }
}
