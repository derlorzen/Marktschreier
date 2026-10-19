<?php

namespace App\Services;

use App\Models\ChangeLog;
use App\Models\Product;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;

/**
 * Alle Änderungen an Artikeln aus der Oberfläche laufen hier durch:
 * nur echte Änderungen speichern, jede im Verlauf protokollieren, Abgleichsstatus neu setzen.
 */
class ProductEditor
{
    /**
     * @param  array<string, mixed>  $productFields
     * @param  array<int, array<string, mixed>>  $variantFields  [variant_id => [feld => wert]]
     * @param  bool  $resolvesConflict  true, wenn jemand den Artikel in der Detailansicht geprüft und gespeichert hat
     * @return int Anzahl geänderter Felder
     */
    public function update(Product $product, array $productFields = [], array $variantFields = [], ?int $userId = null, bool $resolvesConflict = false): int
    {
        return DB::transaction(function () use ($product, $productFields, $variantFields, $userId, $resolvesConflict) {
            $count = 0;
            foreach ($productFields as $field => $value) {
                $count += $this->apply($product, $product, $field, $value, $userId);
            }
            $product->save();

            $product->loadMissing('variants');
            foreach ($variantFields as $variantId => $fields) {
                $variant = $product->variants->firstWhere('id', (int) $variantId);
                if (! $variant) {
                    continue;
                }
                foreach ($fields as $field => $value) {
                    $count += $this->apply($product, $variant, $field, $value, $userId);
                }
                $variant->save();
            }

            if ($count > 0 || $resolvesConflict) {
                // Nur wer den Artikel selbst geprüft hat, löst einen Konflikt auf; Gruppenänderungen nicht.
                if ($resolvesConflict && $product->push_status === Product::STATUS_CONFLICT) {
                    $product->push_status = Product::STATUS_PENDING;
                    $product->push_error = null;
                }
                $product->refreshPushStatus();
                $product->save();
            }

            return $count;
        });
    }

    /** Setzt alle lokalen, noch nicht übertragenen Änderungen auf den letzten Shopify-Stand zurück. */
    public function revertToShopify(Product $product, ?int $userId = null): int
    {
        $product->loadMissing('variants');
        $changes = $product->allPendingChanges();
        $productFields = array_map(fn ($c) => $c['old'], $changes['product']);
        $variantFields = array_map(fn ($fields) => array_map(fn ($c) => $c['old'], $fields), $changes['variants']);

        $count = $this->update($product, $productFields, $variantFields, $userId);
        $product->push_status = Product::STATUS_SYNCED;
        $product->push_error = null;
        $product->refreshPushStatus();
        $product->save();

        return $count;
    }

    private function apply(Product $product, Product|Variant $model, string $field, mixed $value, ?int $userId): int
    {
        $class = $model::class;
        $old = $model->getAttribute($field);
        if ($class::normalizeValue($field, $old) === $class::normalizeValue($field, $value)) {
            return 0;
        }
        $model->setAttribute($field, $value === '' ? null : $value);
        ChangeLog::record($product, $field, $old, $value, 'user', $model instanceof Variant ? $model : null, $userId);

        return 1;
    }
}
