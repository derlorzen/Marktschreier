<?php

namespace App\Support;

class Format
{
    public static function money(mixed $value, int $decimals = 2): string
    {
        if ($value === null || $value === '') {
            return '–';
        }

        return number_format((float) $value, $decimals, ',', '.').' €';
    }

    public static function percent(?float $value): string
    {
        return $value === null ? '–' : number_format($value, 1, ',', '.').' %';
    }

    /** Eingaben wie "12,90" oder "1.299,00" in einen Dezimalwert wandeln. */
    public static function parseDecimal(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $value = str_replace(['€', ' '], '', $value);
        if (str_contains($value, ',')) {
            $value = str_replace(['.', ','], ['', '.'], $value);
        }

        return is_numeric($value) ? $value : null;
    }

    public static function decimalForInput(mixed $value): string
    {
        return $value === null || $value === '' ? '' : number_format((float) $value, 2, ',', '');
    }

    public static function pushStatusBadge(string $status): array
    {
        return [
            'synced' => ['Abgeglichen', 'badge-ok'],
            'pending' => ['Nicht übertragen', 'badge-warn'],
            'error' => ['Fehler', 'badge-bad'],
            'conflict' => ['Konflikt', 'badge-amber'],
        ][$status] ?? [$status, ''];
    }

    public static function shopifyStatus(?string $status): string
    {
        return ['ACTIVE' => 'Aktiv', 'DRAFT' => 'Entwurf', 'ARCHIVED' => 'Archiviert', 'UNLISTED' => 'Nicht gelistet'][$status] ?? (string) $status;
    }
}
