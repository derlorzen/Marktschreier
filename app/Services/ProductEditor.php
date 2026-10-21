<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\ChangeLog;
use App\Models\ChannelPrice;
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

    /**
     * Eigenen Preis für einen Marktplatz setzen. Null entfernt ihn, dann gilt wieder der Shopify-Preis.
     */
    public function setChannelPrice(Variant $variant, Channel $channel, ?string $price, ?int $userId = null): bool
    {
        if ($channel->isShopify()) {
            return $this->update($variant->product, [], [$variant->id => ['price' => $price]], $userId) > 0;
        }
        $existing = ChannelPrice::where('variant_id', $variant->id)->where('channel_id', $channel->id)->first();
        $old = $existing ? number_format((float) $existing->price, 2, '.', '') : null;
        $new = $price === null ? null : number_format((float) $price, 2, '.', '');
        if ($old === $new) {
            return false;
        }
        if ($new === null) {
            $existing?->delete();
        } else {
            ChannelPrice::updateOrCreate(['variant_id' => $variant->id, 'channel_id' => $channel->id], ['price' => $new, 'source' => 'manual', 'price_rule_id' => null]);
        }
        ChangeLog::record($variant->product, 'Preis '.$channel->name, $old, $new, 'user', $variant, $userId);

        return true;
    }

    /** Hauptvariante festlegen, wie bei PlentyONE genau eine je Artikel. */
    public function setMainVariant(Product $product, Variant $variant, ?int $userId = null): bool
    {
        if ($variant->product_id !== $product->id || $variant->is_main) {
            return false;
        }
        DB::transaction(function () use ($product, $variant, $userId) {
            $old = $product->variants()->where('is_main', true)->first();
            $product->variants()->where('is_main', true)->update(['is_main' => false]);
            $variant->forceFill(['is_main' => true])->save();
            ChangeLog::record($product, 'is_main', $old?->label(), $variant->label(), 'user', $variant, $userId);
        });
        $product->unsetRelation('variants');

        return true;
    }

    /**
     * Eigene Merkmale einer Variante setzen. Ein leerer Wert entfernt das eigene Merkmal,
     * dann gilt wieder der Wert vom Artikel.
     *
     * @param  array<string, string|null>  $values
     */
    public function setVariantAttributes(Variant $variant, array $values, ?int $userId = null): int
    {
        return DB::transaction(function () use ($variant, $values, $userId) {
            $count = 0;
            $existing = $variant->attributeValues()->get()->keyBy('key');
            foreach ($values as $key => $value) {
                $key = trim((string) $key);
                $value = trim((string) $value);
                $old = $existing->get($key);
                if ($key === '' || ($old && (string) $old->value === $value) || (! $old && $value === '')) {
                    continue;
                }
                if ($value === '') {
                    $old->delete();
                } elseif ($old) {
                    $old->update(['value' => $value]);
                } else {
                    $variant->attributeValues()->create(['key' => $key, 'value' => $value]);
                }
                ChangeLog::record($variant->product, 'Merkmal: '.$key, $old?->value, $value === '' ? null : $value, 'user', $variant, $userId);
                $count++;
            }
            $variant->unsetRelation('attributeValues');

            return $count;
        });
    }

    /**
     * Bilder einer Variante zuordnen. Nicht gewählte Bilder, die bisher nur für bestimmte Varianten galten,
     * verlieren diese Variante; Bilder ohne Zuordnung bleiben für alle sichtbar.
     *
     * @param  list<int>  $imageIds
     */
    public function setVariantImages(Variant $variant, array $imageIds, ?int $userId = null): int
    {
        $count = 0;
        foreach ($variant->product->images()->get() as $image) {
            $ids = $image->variant_ids ?? [];
            $want = in_array($image->id, $imageIds, true);
            $has = in_array($variant->id, $ids, true);
            if ($want === $has) {
                continue;
            }
            $ids = $want ? [...$ids, $variant->id] : array_values(array_diff($ids, [$variant->id]));
            $image->update(['variant_ids' => $ids ?: null]);
            ChangeLog::record($variant->product, 'Bild '.$image->position, $has ? 'zugeordnet' : null, $want ? 'zugeordnet' : null, 'user', $variant, $userId);
            $count++;
        }

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
