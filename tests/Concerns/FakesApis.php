<?php

namespace Tests\Concerns;

use App\Services\Billbee\BillbeeClient;
use App\Services\Shopify\ShopifyClient;

trait FakesApis
{
    /** @var list<mixed> Antworten, die der Fake der Reihe nach ausliefert (Shopify und Billbee getrennt). */
    protected array $shopifyQueue = [];

    protected array $billbeeQueue = [];

    /** Nächste Shopify-Antworten festlegen (ersetzt noch nicht verbrauchte). */
    protected function queueShopify(mixed ...$responses): void
    {
        $this->shopifyQueue = $responses;
    }

    protected function queueBillbee(mixed ...$responses): void
    {
        $this->billbeeQueue = $responses;
    }

    protected function bindApiClients(): void
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake([
            'test-shop.myshopify.com/*' => fn () => $this->nextFrom($this->shopifyQueue, 'Shopify'),
            'api.billbee.io/*' => fn () => $this->nextFrom($this->billbeeQueue, 'Billbee'),
        ]);
        $this->app->bind(ShopifyClient::class, fn () => new ShopifyClient('test-shop.myshopify.com', 'shpat_test', '2026-10', fn () => null));
        $this->app->bind(BillbeeClient::class, fn () => new BillbeeClient('key', 'user', 'pass', 'https://api.billbee.io/api/v1', fn () => null));
    }

    private function nextFrom(array &$queue, string $name): mixed
    {
        if ($queue === []) {
            throw new \RuntimeException("Keine weitere {$name}-Antwort vorbereitet.");
        }
        $next = array_shift($queue);

        return is_array($next) ? \Illuminate\Support\Facades\Http::response($next) : $next;
    }

    /** Ein Shopify-Produkt wie es die Admin GraphQL API liefert. */
    protected function shopifyNode(array $overrides = [], ?array $variants = null): array
    {
        $variants ??= [
            $this->shopifyVariant(['id' => 'gid://shopify/ProductVariant/11', 'sku' => 'JM-SLV-BLK', 'barcode' => '4260000000011', 'price' => '9.90', 'selectedOptions' => [['name' => 'Farbe', 'value' => 'Schwarz']]]),
            $this->shopifyVariant(['id' => 'gid://shopify/ProductVariant/12', 'sku' => 'JM-SLV-RED', 'barcode' => '4260000000028', 'price' => '9.90', 'position' => 2, 'selectedOptions' => [['name' => 'Farbe', 'value' => 'Rot']]]),
        ];

        return array_replace([
            'id' => 'gid://shopify/Product/1',
            'handle' => 'sleeves-matt',
            'title' => 'Sleeves Matt 100 Stück',
            'descriptionHtml' => '<p>Matte Kartenhüllen</p>',
            'vendor' => 'Johto Market',
            'productType' => 'Sleeves',
            'tags' => ['TCG', 'Sleeves'],
            'status' => 'ACTIVE',
            'updatedAt' => '2026-10-18T10:00:00Z',
            'seo' => ['title' => null, 'description' => null],
            'options' => [['name' => 'Farbe', 'position' => 1, 'optionValues' => [['name' => 'Schwarz'], ['name' => 'Rot']]]],
            'media' => ['nodes' => [['id' => 'gid://shopify/MediaImage/5', 'alt' => 'Sleeves', 'image' => ['url' => 'https://cdn.shopify.com/s/sleeves.jpg']]]],
            'variants' => ['nodes' => $variants],
        ], $overrides);
    }

    protected function shopifyVariant(array $o): array
    {
        $barcode = $o['barcode'] ?? null;
        unset($o['barcode']);

        return array_replace([
            'id' => 'gid://shopify/ProductVariant/11',
            'title' => 'Default',
            'sku' => 'SKU',
            'price' => '9.90',
            'compareAtPrice' => null,
            'position' => 1,
            'barcodes' => ['nodes' => $barcode ? [['value' => $barcode]] : []],
            'selectedOptions' => [],
            'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/'.random_int(100, 999)],
        ], $o);
    }

    /** @param list<array> $nodes */
    protected function productsPage(array $nodes, bool $hasNext = false, ?string $cursor = null): array
    {
        return ['data' => ['products' => ['pageInfo' => ['hasNextPage' => $hasNext, 'endCursor' => $cursor], 'nodes' => $nodes]],
            'extensions' => ['cost' => ['throttleStatus' => ['maximumAvailable' => 2000, 'currentlyAvailable' => 1900, 'restoreRate' => 100]]]];
    }
}
