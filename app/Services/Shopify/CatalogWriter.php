<?php

namespace App\Services\Shopify;

use App\Models\ChangeLog;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Variant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Anlegen in Shopify: neue Artikel, neue Varianten, Bilder, Merkmale als Metafelder.
 * Alle Aufrufe an einer Stelle, damit Anpassungen an neue API-Versionen leicht fallen.
 */
class CatalogWriter
{
    public const METAFIELD_NAMESPACE = 'marktschreier';

    public function __construct(private ShopifyClient $client, private ProductImporter $importer)
    {
    }

    private function errors(array $payload): void
    {
        $errors = $payload['userErrors'] ?? [];
        if ($errors) {
            throw new ShopifyException(collect($errors)->map(fn ($e) => trim((is_array($e['field'] ?? null) ? implode('.', $e['field']).': ' : '').($e['message'] ?? '')))->implode(' · '));
        }
    }

    /** Lokal angelegten Artikel samt Varianten in Shopify anlegen (productSet, synchron). */
    public function createProduct(Product $product, ?int $userId = null): Product
    {
        $product->loadMissing('variants', 'images');
        $options = collect($product->options ?? [])->filter(fn ($o) => ($o['name'] ?? '') !== '' && $o['values']);
        $input = [
            'title' => $product->title,
            'descriptionHtml' => (string) $product->description_html,
            'vendor' => (string) $product->vendor,
            'productType' => (string) $product->product_type,
            'tags' => $product->tags ?? [],
            'status' => $product->status ?: 'DRAFT',
            'seo' => ['title' => (string) $product->seo_title, 'description' => (string) $product->seo_description],
        ];
        $input['productOptions'] = [['name' => 'Title', 'position' => 1, 'values' => [['name' => 'Default Title']]]];
        if ($options->isNotEmpty()) {
            $input['productOptions'] = $options->values()->map(fn ($o, $i) => [
                'name' => $o['name'], 'position' => $i + 1, 'values' => collect($o['values'])->map(fn ($v) => ['name' => $v])->all(),
            ])->all();
        }
        $input['variants'] = $product->variants->map(function (Variant $v, $i) use ($options) {
            $row = [
                'position' => $i + 1,
                'price' => number_format((float) $v->price, 2, '.', ''),
                'inventoryItem' => array_filter(['sku' => $v->sku, 'tracked' => true, 'cost' => $v->cost_net !== null ? (float) $v->cost_net : null], fn ($x) => $x !== null),
            ];
            if ($v->compare_at_price !== null) {
                $row['compareAtPrice'] = number_format((float) $v->compare_at_price, 2, '.', '');
            }
            if ($v->barcode) {
                $row['barcodes'] = [['value' => $v->barcode]];
            }
            $row['optionValues'] = $options->isEmpty()
                ? [['optionName' => 'Title', 'name' => 'Default Title']]
                : $options->values()->map(fn ($o, $oi) => ['optionName' => $o['name'], 'name' => $v->{'option'.($oi + 1)}])->all();

            return $row;
        })->values()->all();

        $data = $this->client->query(<<<'GQL'
mutation Create($input: ProductSetInput!) {
  productSet(input: $input, synchronous: true) {
    product { id variants(first: 100) { nodes { id sku } } }
    userErrors { field message code }
  }
}
GQL, ['input' => $input]);
        $this->errors($data['productSet'] ?? []);
        $created = $data['productSet']['product'] ?? null;
        if (! $created) {
            throw new ShopifyException('Shopify hat den Artikel nicht angelegt.');
        }

        // Shopify-IDs übernehmen, damit der anschließende Import die lokalen Datensätze trifft.
        $product->update(['shopify_id' => $created['id']]);
        $bySku = collect($created['variants']['nodes'] ?? [])->keyBy(fn ($n) => mb_strtolower((string) $n['sku']));
        foreach ($product->variants as $i => $v) {
            $node = $bySku->get(mb_strtolower((string) $v->sku)) ?? ($created['variants']['nodes'][$i] ?? null);
            if ($node) {
                $v->update(['shopify_id' => $node['id']]);
            }
        }
        $product = $this->importer->importOne($created['id']) ?? $product->fresh();
        $product->markAsSynced();
        foreach ($product->variants as $v) {
            $v->markAsSynced();
            $v->save();
        }
        $product->push_status = Product::STATUS_SYNCED;
        $product->save();
        ChangeLog::record($product, 'In Shopify angelegt', null, $created['id'], 'push', null, $userId);
        if ($product->attributeValues()->exists()) {
            $this->pushMetafields($product);
        }

        return $product;
    }

    /**
     * Neue Variante zu einem Artikel mit Optionen (z. B. neue Farbe). Neue Optionswerte legt Shopify mit an.
     *
     * @param  array{options: list<string>, sku: ?string, barcode: ?string, price: string, compare_at_price: ?string}  $data
     */
    public function addVariant(Product $product, array $data, ?int $userId = null): Variant
    {
        $options = collect($product->options ?? [])->values();
        if ($options->isEmpty() || ($options->count() === 1 && in_array($options[0]['name'], ['Title', 'Titel'], true))) {
            throw new ShopifyException('Der Artikel hat keine Optionen wie Farbe oder Größe. Neue Varianten bitte über „Neuer Artikel“ oder in Shopify anlegen.');
        }
        $row = [
            'price' => $data['price'],
            'optionValues' => $options->map(fn ($o, $i) => ['optionName' => $o['name'], 'name' => $data['options'][$i] ?? ''])->all(),
            'inventoryItem' => array_filter(['sku' => $data['sku'] ?? null, 'tracked' => true]),
        ];
        if (! empty($data['compare_at_price'])) {
            $row['compareAtPrice'] = $data['compare_at_price'];
        }
        if (! empty($data['barcode'])) {
            $row['barcodes'] = [['value' => $data['barcode']]];
        }
        $res = $this->client->query(<<<'GQL'
mutation Add($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
  productVariantsBulkCreate(productId: $productId, variants: $variants) {
    productVariants { id sku }
    userErrors { field message code }
  }
}
GQL, ['productId' => $product->shopify_id, 'variants' => [$row]]);
        $this->errors($res['productVariantsBulkCreate'] ?? []);
        $product = $this->importer->importOne($product->shopify_id);
        $variant = $product->variants()->where('shopify_id', $res['productVariantsBulkCreate']['productVariants'][0]['id'] ?? '')->first();
        ChangeLog::record($product, 'Neue Variante', null, implode(' / ', array_filter($data['options'])), 'push', $variant, $userId);

        return $variant ?? $product->variants()->latest('id')->first();
    }

    /** Bild hochladen: Staged Upload, dann an den Artikel hängen. */
    public function uploadImage(Product $product, string $path, string $filename, string $mime, ?string $alt = null): void
    {
        $res = $this->client->query(<<<'GQL'
mutation Stage($input: [StagedUploadInput!]!) {
  stagedUploadsCreate(input: $input) {
    stagedTargets { url resourceUrl parameters { name value } }
    userErrors { field message }
  }
}
GQL, ['input' => [['resource' => 'IMAGE', 'filename' => $filename, 'mimeType' => $mime, 'httpMethod' => 'POST', 'fileSize' => (string) filesize($path)]]]);
        $this->errors($res['stagedUploadsCreate'] ?? []);
        $target = $res['stagedUploadsCreate']['stagedTargets'][0] ?? null;
        if (! $target) {
            throw new ShopifyException('Shopify hat kein Upload-Ziel geliefert.');
        }
        $req = Http::timeout(120)->asMultipart();
        foreach ($target['parameters'] as $p) {
            $req = $req->attach($p['name'], $p['value']);
        }
        $upload = $req->attach('file', fopen($path, 'r'), $filename)->post($target['url']);
        if (! $upload->successful()) {
            throw new ShopifyException('Hochladen fehlgeschlagen (HTTP '.$upload->status().').');
        }
        $res = $this->client->query(<<<'GQL'
mutation Attach($product: ProductUpdateInput!, $media: [CreateMediaInput!]) {
  productUpdate(product: $product, media: $media) {
    product { id }
    userErrors { field message }
  }
}
GQL, ['product' => ['id' => $product->shopify_id], 'media' => [['originalSource' => $target['resourceUrl'], 'mediaContentType' => 'IMAGE', 'alt' => (string) $alt]]]);
        $this->errors($res['productUpdate'] ?? []);
        $this->importer->importOne($product->shopify_id);
    }

    /** @param list<string> $orderedMediaIds Reihenfolge der Bilder, erstes = Hauptbild */
    public function reorderImages(Product $product, array $orderedMediaIds): void
    {
        $res = $this->client->query(<<<'GQL'
mutation Reorder($id: ID!, $moves: [MoveInput!]!) {
  productReorderMedia(id: $id, moves: $moves) {
    job { id }
    mediaUserErrors { field message }
  }
}
GQL, ['id' => $product->shopify_id, 'moves' => collect($orderedMediaIds)->values()->map(fn ($id, $i) => ['id' => $id, 'newPosition' => (string) $i])->all()]);
        $errors = $res['productReorderMedia']['mediaUserErrors'] ?? [];
        if ($errors) {
            throw new ShopifyException(collect($errors)->pluck('message')->implode(' · '));
        }
        foreach ($orderedMediaIds as $i => $id) {
            ProductImage::where('product_id', $product->id)->where('shopify_id', $id)->update(['position' => $i + 1]);
        }
    }

    public function deleteImage(Product $product, string $mediaId): void
    {
        $res = $this->client->query(<<<'GQL'
mutation Remove($productId: ID!, $mediaIds: [ID!]!) {
  productDeleteMedia(productId: $productId, mediaIds: $mediaIds) {
    deletedMediaIds
    mediaUserErrors { field message }
  }
}
GQL, ['productId' => $product->shopify_id, 'mediaIds' => [$mediaId]]);
        $errors = $res['productDeleteMedia']['mediaUserErrors'] ?? [];
        if ($errors) {
            throw new ShopifyException(collect($errors)->pluck('message')->implode(' · '));
        }
        ProductImage::where('product_id', $product->id)->where('shopify_id', $mediaId)->delete();
    }

    public function updateAlt(Product $product, string $mediaId, string $alt): void
    {
        $res = $this->client->query(<<<'GQL'
mutation Alt($files: [FileUpdateInput!]!) {
  fileUpdate(files: $files) {
    files { id }
    userErrors { field message }
  }
}
GQL, ['files' => [['id' => $mediaId, 'alt' => $alt]]]);
        $this->errors($res['fileUpdate'] ?? []);
        ProductImage::where('product_id', $product->id)->where('shopify_id', $mediaId)->update(['alt' => $alt]);
    }

    /** Merkmale als Metafelder „marktschreier.<schlüssel>“ übertragen. */
    public function pushMetafields(Product $product): int
    {
        $attrs = $product->attributeValues()->get();
        if ($attrs->isEmpty() || ! $product->shopify_id) {
            return 0;
        }
        $metafields = $attrs->filter(fn ($a) => trim((string) $a->value) !== '')->map(fn ($a) => [
            'ownerId' => $product->shopify_id,
            'namespace' => self::METAFIELD_NAMESPACE,
            'key' => self::metafieldKey($a->key),
            'type' => 'single_line_text_field',
            'value' => mb_substr((string) $a->value, 0, 5000),
        ])->values();
        foreach ($metafields->chunk(25) as $chunk) {
            $res = $this->client->query(<<<'GQL'
mutation Meta($metafields: [MetafieldsSetInput!]!) {
  metafieldsSet(metafields: $metafields) {
    metafields { key }
    userErrors { field message code }
  }
}
GQL, ['metafields' => $chunk->values()->all()]);
            $this->errors($res['metafieldsSet'] ?? []);
        }
        $product->forceFill(['attributes_pushed_hash' => $product->attributesHash()])->save();

        return $metafields->count();
    }

    public static function metafieldKey(string $name): string
    {
        $key = Str::of(Str::ascii($name))->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(60, '');

        return (string) ($key->isEmpty() ? 'merkmal' : $key);
    }
}
