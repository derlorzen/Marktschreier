<?php

namespace App\Models\Concerns;

/**
 * Merkt sich, wie ein Datensatz zuletzt in Shopify aussah (synced_snapshot),
 * und erkennt daraus, welche Felder lokal geändert wurden und noch übertragen werden müssen.
 */
trait TracksShopifySync
{
    /** @return list<string> */
    abstract public static function pushableFields(): array;

    public static function normalizeValue(string $field, mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }
        if (in_array($field, ['price', 'compare_at_price'], true)) {
            return number_format((float) $value, 2, '.', '');
        }
        if ($field === 'tags') {
            $tags = array_values(array_filter(array_map('trim', (array) $value), fn ($t) => $t !== ''));
            sort($tags, SORT_NATURAL | SORT_FLAG_CASE);

            return $tags ?: null;
        }

        return is_string($value) ? trim($value) : $value;
    }

    /** @return array<string, mixed> */
    public function currentPushableValues(): array
    {
        $values = [];
        foreach (static::pushableFields() as $field) {
            $values[$field] = static::normalizeValue($field, $this->getAttribute($field));
        }

        return $values;
    }

    /**
     * Felder, die lokal anders sind als zuletzt in Shopify.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function pendingChanges(): array
    {
        $snapshot = $this->synced_snapshot ?? [];
        $changes = [];
        foreach ($this->currentPushableValues() as $field => $value) {
            $old = static::normalizeValue($field, $snapshot[$field] ?? null);
            if ($old !== $value) {
                $changes[$field] = ['old' => $old, 'new' => $value];
            }
        }

        return $changes;
    }

    public function isLocallyChanged(string $field): bool
    {
        return array_key_exists($field, $this->pendingChanges());
    }

    public function markAsSynced(): void
    {
        $this->synced_snapshot = $this->currentPushableValues();
    }
}
