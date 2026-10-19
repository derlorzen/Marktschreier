<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** GTIN-8, -12 (UPC), -13 (EAN) oder -14 mit gültiger Prüfziffer. */
class Gtin implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (! self::isValid((string) $value)) {
            $fail('Keine gültige GTIN/EAN (8, 12, 13 oder 14 Ziffern mit passender Prüfziffer).');
        }
    }

    public static function isValid(string $code): bool
    {
        if (! preg_match('/^\d{8}$|^\d{12,14}$/', $code)) {
            return false;
        }
        $digits = array_map('intval', str_split(str_pad($code, 14, '0', STR_PAD_LEFT)));
        $check = array_pop($digits);
        $sum = 0;
        foreach ($digits as $i => $d) {
            $sum += $d * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === $check;
    }
}
