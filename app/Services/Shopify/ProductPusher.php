<?php

namespace App\Services\Shopify;

use App\Models\ChangeLog;
use App\Models\Product;
use App\Models\SyncRun;

/**
 * Überträgt lokal geänderte Felder eines Artikels zu Shopify. Es werden nur die Felder geschickt,
 * die sich seit dem letzten Abgleich geändert haben, nichts anderes wird angefasst.
 */
class ProductPusher
{
    private const PRODUCT_MUTATION = <<<'GQL'
mutation UpdateProduct($product: ProductUpdateInput!) {
  productUpdate(product: $product) {
    product { id updatedAt }
    userErrors { field message }
  }
}
GQL;

    private const VARIANTS_MUTATION = <<<'GQL'
mutation UpdateVariants($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
  productVariantsBulkUpdate(productId: $productId, variants: $variants) {
    productVariants { id }
    userErrors { field message }
  }
}
GQL;

    public function __construct(private ShopifyClient $client)
    {
    }

    /** @return array{product: array<string, mixed>|null, variants: list<array<string, mixed>>} */
    public function buildPayload(Product $product): array
    {
        $product->loadMissing('variants');
        $changes = $product->allPendingChanges();

        $productInput = null;
        if ($changes['product'] !== []) {
            $productInput = ['id' => $product->shopify_id];
            $map = ['title' => 'title', 'description_html' => 'descriptionHtml', 'vendor' => 'vendor', 'product_type' => 'productType', 'tags' => 'tags', 'status' => 'status'];
            foreach ($changes['product'] as $field => $change) {
                if (isset($map[$field])) {
                    $value = $change['new'];
                    if ($field === 'tags') {
                        $value = $value ?? [];
                    } elseif ($field !== 'status') {
                        $value = $value ?? '';
                    }
                    $productInput[$map[$field]] = $value;
                }
            }
            if (isset($changes['product']['seo_title']) || isset($changes['product']['seo_description'])) {
                $productInput['seo'] = [
                    'title' => $product->seo_title ?? '',
                    'description' => $product->seo_description ?? '',
                ];
            }
        }

        $variants = [];
        foreach ($product->variants as $variant) {
            $vc = $changes['variants'][$variant->id] ?? [];
            if ($vc === [] || ! $variant->shopify_id) {
                continue;
            }
            $input = ['id' => $variant->shopify_id];
            if (array_key_exists('price', $vc)) {
                $input['price'] = $vc['price']['new'] ?? '0.00';
            }
            if (array_key_exists('compare_at_price', $vc)) {
                $input['compareAtPrice'] = $vc['compare_at_price']['new'];
            }
            if (array_key_exists('barcode', $vc)) {
                $input['barcodes'] = $vc['barcode']['new'] ? [['value' => $vc['barcode']['new']]] : [];
            }
            if (array_key_exists('sku', $vc)) {
                $input['inventoryItem'] = ['sku' => $vc['sku']['new'] ?? ''];
            }
            $variants[] = $input;
        }

        return ['product' => $productInput, 'variants' => $variants];
    }

    /**
     * @return bool true bei Erfolg; Fehler stehen danach in $product->push_error
     */
    public function push(Product $product, ?int $userId = null, ?SyncRun $run = null): bool
    {
        if (! $product->shopify_id) {
            // Nur in Marktschreier angelegt: jetzt in Shopify anlegen.
            try {
                app(CatalogWriter::class)->createProduct($product, $userId);

                return true;
            } catch (ShopifyException $e) {
                $product->update(['push_error' => $e->getMessage()]);

                return false;
            }
        }

        $payload = $this->buildPayload($product);
        if ($payload['product'] === null && $payload['variants'] === []) {
            if ($product->attributesPending()) {
                try {
                    app(CatalogWriter::class)->pushMetafields($product);
                } catch (ShopifyException $e) {
                    $product->update(['push_status' => Product::STATUS_ERROR, 'push_error' => 'Merkmale: '.$e->getMessage()]);

                    return false;
                }
            }
            $product->update(['push_status' => Product::STATUS_SYNCED, 'push_error' => null]);

            return true;
        }

        $run ??= SyncRun::start(SyncRun::TYPE_SHOPIFY_PUSH);
        $changes = $product->allPendingChanges();
        $errors = [];

        try {
            if ($payload['product'] !== null) {
                $res = $this->client->query(self::PRODUCT_MUTATION, ['product' => $payload['product']]);
                $errors = array_merge($errors, $res['productUpdate']['userErrors'] ?? []);
            }
            if ($payload['variants'] !== [] && $errors === []) {
                $res = $this->client->query(self::VARIANTS_MUTATION, ['productId' => $product->shopify_id, 'variants' => $payload['variants']]);
                $errors = array_merge($errors, $res['productVariantsBulkUpdate']['userErrors'] ?? []);
            }
        } catch (ShopifyException $e) {
            $errors[] = ['field' => null, 'message' => $e->getMessage()];
        }

        if ($errors !== []) {
            $msg = collect($errors)->map(fn ($e) => trim((is_array($e['field'] ?? null) ? implode('.', $e['field']).': ' : '').($e['message'] ?? '')))->implode(' · ');
            $product->update(['push_status' => Product::STATUS_ERROR, 'push_error' => $msg]);
            $run->fail($msg, ['product_id' => $product->id]);

            return false;
        }

        foreach ($changes['product'] as $field => $c) {
            ChangeLog::record($product, $field, $c['old'], $c['new'], 'push', null, $userId);
        }
        foreach ($product->variants as $variant) {
            foreach ($changes['variants'][$variant->id] ?? [] as $field => $c) {
                ChangeLog::record($product, $field, $c['old'], $c['new'], 'push', $variant, $userId);
            }
            $variant->markAsSynced();
            $variant->save();
        }
        $product->markAsSynced();
        if ($product->attributesPending()) {
            try {
                app(CatalogWriter::class)->pushMetafields($product);
            } catch (ShopifyException $e) {
                $product->push_error = 'Merkmale: '.$e->getMessage();
            }
        }
        $product->push_status = Product::STATUS_SYNCED;
        $product->push_error = $product->push_error && str_starts_with($product->push_error, 'Merkmale:') ? $product->push_error : null;
        $product->last_pushed_at = now();
        $product->save();
        $run->finish(['product_id' => $product->id, 'fields' => count($changes['product']) + count($changes['variants'])]);

        return true;
    }
}
