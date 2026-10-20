<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Einstellungen der Instanz (Zugangsdaten werden verschlüsselt gespeichert).
 * Werte aus der Datenbank haben Vorrang, sonst gilt die Umgebungsvariable aus config/marktschreier.php.
 */
class Setting extends Model
{
    protected $guarded = ['id'];

    /** Schlüssel, die verschlüsselt abgelegt werden. */
    public const SECRET = ['shopify.token', 'shopify.client_secret', 'billbee.api_key', 'billbee.password', 'ai.api_key', 'plenty.password'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::rememberForever('settings.all', fn () => self::pluck('value', 'key')->all());
        if (array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== '') {
            $value = $all[$key];

            return in_array($key, self::SECRET, true) ? Crypt::decryptString($value) : $value;
        }

        return config('marktschreier.'.$key, $default);
    }

    public static function put(string $key, ?string $value): void
    {
        $stored = ($value !== null && $value !== '' && in_array($key, self::SECRET, true)) ? Crypt::encryptString($value) : $value;
        self::updateOrCreate(['key' => $key], ['value' => $stored]);
        Cache::forget('settings.all');
    }
}
