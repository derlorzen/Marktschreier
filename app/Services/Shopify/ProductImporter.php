<?php

namespace App\Services\Shopify;

use App\Models\ChangeLog;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\SyncRun;
use App\Models\Variant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Holt Artikel aus Shopify und gleicht sie mit dem lokalen Bestand ab.
 *
 * Regel: Marktschreier ist führend. Ein Feld wird nur überschrieben, wenn es lokal seit dem
 * letzten Abgleich nicht geändert wurde. Haben sich lokal und in Shopify dasselbe Feld geändert,
 * bleibt der lokale Wert und der Artikel wird als Konflikt markiert.
 */
class ProductImporter
{
    public const PAGE_SIZE = 25;

    private const QUERY = <<<'GQL'
query Products($first: Int!, $after: String, $query: String) {
  products(first: $first, after: $after, query: $query, sortKey: UPDATED_AT) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id handle title descriptionHtml vendor productType tags status updatedAt
      seo { title description }
      options { name position optionValues { name } }
      media(first: 20) { nodes { ... on MediaImage { id alt image { url } } } }
      variants(first: 100) {
        nodes {
          id title sku price compareAtPrice position
          barcodes(first: 1) { nodes { value } }
          selectedOptions { name value }
          inventoryItem { id }
        }
      }
    }
  }
}
GQL;

    private array $stats = ['products_new' => 0, 'products_updated' => 0, 'variants' => 0, 'conflicts' => 0, 'pages' => 0];

    public function __construct(private ShopifyClient $client)
    {
    }

    /** @param  Carbon|null  $since  nur seitdem geänderte Artikel holen (Delta) */
    public function run(?Carbon $since = null, ?SyncRun $run = null): array
    {
        $run ??= SyncRun::start(SyncRun::TYPE_SHOPIFY_IMPORT);
        $filter = $since ? "updated_at:>='".$since->copy()->utc()->format('Y-m-d\TH:i:s\Z')."'" : null;

        try {
            $cursor = null;
            do {
                $data = $this->client->query(self::QUERY, ['first' => self::PAGE_SIZE, 'after' => $cursor, 'query' => $filter]);
                $page = $data['products'] ?? ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]];
                foreach ($page['nodes'] as $node) {
                    $this->importNode($node);
                }
                $this->stats['pages']++;
                $cursor = $page['pageInfo']['endCursor'] ?? null;
                $run->update(['stats' => $this->stats]);
            } while (($page['pageInfo']['hasNextPage'] ?? false) && $cursor);
        } catch (\Throwable $e) {
            $run->fail($e->getMessage(), $this->stats);
            throw $e;
        }

        $run->finish($this->stats, $since ? 'Nur Änderungen seit '.$since->format('d.m.Y H:i') : 'Vollständiger Import');

        return $this->stats;
    }

    /** @param  array<string, mixed>  $node */
    public function importNode(array $node): Product
    {
        return DB::transaction(function () use ($node) {
            $product = Product::with('variants')->firstOrNew(['shopify_id' => $node['id']]);
            $isNew = ! $product->exists;

            $remote = [
                'title' => $node['title'] ?? '',
                'description_html' => $node['descriptionHtml'] ?? null,
                'vendor' => $node['vendor'] ?? null,
                'product_type' => $node['productType'] ?? null,
                'tags' => $node['tags'] ?? [],
                'status' => $node['status'] ?? 'ACTIVE',
                'seo_title' => $node['seo']['title'] ?? null,
                'seo_description' => $node['seo']['description'] ?? null,
            ];

            $conflict = $this->mergeFields($product, $remote, $isNew);

            $product->handle = $node['handle'] ?? $product->handle;
            $product->options = collect($node['options'] ?? [])->map(fn ($o) => [
                'name' => $o['name'], 'position' => $o['position'] ?? null,
                'values' => collect($o['optionValues'] ?? [])->pluck('name')->all(),
            ])->all();
            $product->shopify_updated_at = isset($node['updatedAt']) ? Carbon::parse($node['updatedAt']) : null;
            $product->save();

            $conflict = $this->importVariants($product, $node['variants']['nodes'] ?? [], $isNew) || $conflict;
            $this->importImages($product, $node['media']['nodes'] ?? []);

            $product->load('variants');
            if ($conflict) {
                $product->push_status = Product::STATUS_CONFLICT;
                $product->push_error = 'Dieselben Felder wurden hier und in Shopify geändert. Es gilt der Wert aus Marktschreier, bitte prüfen und übertragen.';
                $this->stats['conflicts']++;
            } else {
                $product->refreshPushStatus();
            }
            $product->save();

            $this->stats[$isNew ? 'products_new' : 'products_updated']++;

            return $product;
        });
    }

    /**
     * Übernimmt Shopify-Werte für alle Felder, die lokal nicht geändert wurden.
     *
     * @param  array<string, mixed>  $remote
     * @return bool true, wenn ein Konflikt entstanden ist
     */
    private function mergeFields(Product|Variant $model, array $remote, bool $isNew): bool
    {
        $conflict = false;
        $snapshot = $model->synced_snapshot ?? [];
        $class = $model::class;

        foreach ($remote as $field => $value) {
            $remoteNorm = $class::normalizeValue($field, $value);
            if ($isNew || ! $model->exists) {
                $model->setAttribute($field, $value);

                continue;
            }
            $localChanged = $model->isLocallyChanged($field);
            $remoteChanged = $class::normalizeValue($field, $snapshot[$field] ?? null) !== $remoteNorm;

            if (! $localChanged) {
                if ($remoteChanged && $model->exists && $model instanceof Product) {
                    ChangeLog::record($model, $field, $model->getAttribute($field), $value, 'import');
                }
                if ($remoteChanged && $model instanceof Variant) {
                    ChangeLog::record($model->product, $field, $model->getAttribute($field), $value, 'import', $model);
                }
                $model->setAttribute($field, $value);
            } elseif ($remoteChanged && $class::normalizeValue($field, $model->getAttribute($field)) !== $remoteNorm) {
                $conflict = true;
            }
        }

        // Neuer Stand in Shopify ist ab jetzt die Vergleichsbasis.
        $newSnapshot = [];
        foreach ($remote as $field => $value) {
            $newSnapshot[$field] = $class::normalizeValue($field, $value);
        }
        $model->synced_snapshot = $newSnapshot;

        return $conflict;
    }

    /** @param  list<array<string, mixed>>  $nodes */
    private function importVariants(Product $product, array $nodes, bool $isNew): bool
    {
        $conflict = false;
        $seen = [];
        foreach ($nodes as $i => $node) {
            $variant = $product->variants->firstWhere('shopify_id', $node['id']) ?? new Variant(['product_id' => $product->id, 'shopify_id' => $node['id']]);
            $variant->setRelation('product', $product);
            $remote = [
                'price' => $node['price'] ?? 0,
                'compare_at_price' => $node['compareAtPrice'] ?? null,
                'sku' => $node['sku'] ?? null,
                'barcode' => $node['barcodes']['nodes'][0]['value'] ?? null,
            ];
            $conflict = $this->mergeFields($variant, $remote, ! $variant->exists) || $conflict;

            $options = collect($node['selectedOptions'] ?? [])->pluck('value')->values();
            $variant->fill([
                'title' => $node['title'] ?? null,
                'position' => $node['position'] ?? $i + 1,
                'inventory_item_id' => $node['inventoryItem']['id'] ?? null,
                'option1' => $options[0] ?? null,
                'option2' => $options[1] ?? null,
                'option3' => $options[2] ?? null,
            ]);
            $variant->save();
            $seen[] = $variant->id;
            $this->stats['variants']++;
        }

        // In Shopify gelöschte Varianten entfernen (Varianten werden in Stufe 1 in Shopify angelegt).
        if (! $isNew) {
            $product->variants()->whereNotIn('id', $seen)->delete();
        }

        return $conflict;
    }

    /** @param  list<array<string, mixed>>  $nodes */
    private function importImages(Product $product, array $nodes): void
    {
        $product->images()->delete();
        $pos = 1;
        foreach ($nodes as $node) {
            if (empty($node['image']['url'])) {
                continue;
            }
            ProductImage::create([
                'product_id' => $product->id,
                'shopify_id' => $node['id'] ?? null,
                'url' => $node['image']['url'],
                'alt' => $node['alt'] ?? null,
                'position' => $pos++,
            ]);
        }
    }
}
