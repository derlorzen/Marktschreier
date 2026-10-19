<?php

namespace App\Support;

use App\Models\Product;

/** Geöffnete Artikel als Reiter (wie in plenty), je Sitzung gemerkt. */
class OpenTabs
{
    private const KEY = 'open_products';

    private const MAX = 12;

    /** @return list<int> */
    public static function ids(): array
    {
        return array_values(array_map('intval', session(self::KEY, [])));
    }

    public static function open(int $id): void
    {
        $ids = self::ids();
        if (! in_array($id, $ids, true)) {
            $ids[] = $id;
            // Bei zu vielen Reitern fällt der älteste weg.
            $ids = array_slice($ids, -self::MAX);
        }
        session([self::KEY => $ids]);
    }

    /** Schließt einen Reiter und liefert den Nachbarn, der danach aktiv werden sollte. */
    public static function close(int $id): ?int
    {
        $ids = self::ids();
        $pos = array_search($id, $ids, true);
        if ($pos === false) {
            return null;
        }
        array_splice($ids, $pos, 1);
        session([self::KEY => $ids]);

        return $ids[$pos] ?? $ids[$pos - 1] ?? null;
    }

    public static function closeAll(): void
    {
        session()->forget(self::KEY);
    }

    /** @return \Illuminate\Support\Collection<int, Product> in Reihenfolge der Reiter */
    public static function products()
    {
        $ids = self::ids();
        if ($ids === []) {
            return collect();
        }
        $products = Product::whereIn('id', $ids)->get(['id', 'title', 'push_status'])->keyBy('id');

        return collect($ids)->map(fn ($id) => $products->get($id))->filter()->values();
    }
}
