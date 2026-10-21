<?php

namespace App\Services\Marketplaces\Amazon;

use App\Models\Channel;
use App\Models\Listing;
use App\Models\Variant;
use App\Services\Marketplaces\MarketplaceException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Neues Produkt (eigene ASIN) bei Amazon anlegen. Pflichtangaben kommen aus der
 * Produkttyp-Definition (JSON Schema) von Amazon und werden als Formular angezeigt.
 * Varianten-Familien (Eltern/Kind) bleiben vorerst Seller Central vorbehalten.
 */
class AmazonProductBuilder
{
    /** Diese Felder setzt Marktschreier selbst bzw. sie sind Teil jedes Werts. */
    private const META = ['marketplace_id', 'language_tag'];

    public function __construct(private AmazonClient $client)
    {
    }

    /** @return list<array{name: string, title: string}> */
    public function searchProductTypes(string $keywords): array
    {
        $json = $this->client->request('GET', '/definitions/2020-09-01/productTypes', [
            'marketplaceIds' => $this->client->marketplaceId(), 'keywords' => mb_substr($keywords, 0, 100), 'locale' => 'de_DE', 'searchLocale' => 'de_DE',
        ]);

        return collect($json['productTypes'] ?? [])->take(15)
            ->map(fn ($t) => ['name' => $t['name'], 'title' => $t['displayName'] ?? $t['name']])->values()->all();
    }

    /**
     * Pflichtfelder eines Produkttyps als einfache Feldbeschreibungen.
     *
     * @return list<array{name: string, title: string, description: ?string, kind: string, multi: bool, max: ?int, parts: list<array>}>
     */
    public function requiredFields(string $productType): array
    {
        $schema = $this->schema($productType);
        $fields = [];
        foreach ($schema['required'] ?? [] as $name) {
            $prop = $schema['properties'][$name] ?? null;
            if (! $prop) {
                continue;
            }
            $fields[] = $this->describe($name, $prop);
        }

        return $fields;
    }

    /** Lädt das Schema einmal je Produkttyp und Version. */
    public function schema(string $productType): array
    {
        return Cache::remember('amazon.ptd.'.$productType, 86400, function () use ($productType) {
            $def = $this->client->request('GET', '/definitions/2020-09-01/productTypes/'.rawurlencode($productType), [
                'marketplaceIds' => $this->client->marketplaceId(), 'requirements' => 'LISTING', 'requirementsEnforced' => 'ENFORCED', 'locale' => 'de_DE',
            ]);
            $url = $def['schema']['link']['resource'] ?? null;
            if (! $url) {
                throw new MarketplaceException('Amazon liefert für diesen Produkttyp keine Beschreibung.');
            }
            $res = Http::timeout(60)->get($url);
            if (! $res->successful()) {
                throw new MarketplaceException('Die Beschreibung des Produkttyps konnte nicht geladen werden.');
            }

            return $res->json() ?? [];
        });
    }

    private function describe(string $name, array $prop): array
    {
        $item = $prop['items'] ?? [];
        $parts = [];
        $complex = false;
        foreach ($item['properties'] ?? [] as $sub => $def) {
            if (in_array($sub, self::META, true)) {
                continue;
            }
            $type = $def['type'] ?? 'string';
            if (in_array($type, ['object', 'array'], true)) {
                $complex = true;

                continue;
            }
            $parts[] = [
                'name' => $sub,
                'title' => $def['title'] ?? $sub,
                'type' => $type,
                'required' => in_array($sub, $item['required'] ?? [], true),
                'enum' => isset($def['enum']) ? array_combine(
                    array_map('strval', $def['enum']),
                    array_map('strval', $def['enumNames'] ?? $def['enum'])
                ) : null,
                'max' => $def['maxLength'] ?? null,
            ];
        }

        return [
            'name' => $name,
            'title' => $prop['title'] ?? $name,
            'description' => $prop['description'] ?? null,
            'kind' => $complex ? 'complex' : (count($parts) === 1 && $parts[0]['name'] === 'value' ? 'simple' : 'group'),
            'multi' => ($prop['maxItems'] ?? 1) > 1,
            'max' => $prop['maxItems'] ?? null,
            'language' => isset($item['properties']['language_tag']),
            'marketplace' => isset($item['properties']['marketplace_id']),
            'parts' => $parts,
        ];
    }

    /**
     * Vorschläge aus den Artikeldaten für die üblichen Pflichtfelder.
     *
     * @return array<string, mixed> [feld => wert] bzw. [feld => [teil => wert]] bzw. JSON für komplexe Felder
     */
    public function prefill(Variant $variant, array $fields): array
    {
        $product = $variant->product;
        $amazon = Channel::where('key', 'amazon')->first();
        $content = $amazon ? $product->contentFor($amazon) : null;
        // Merkmale der Variante, Fehlendes erbt sie vom Artikel
        $attrs = collect($variant->effectiveAttributes())->mapWithKeys(fn ($value, $key) => [mb_strtolower($key) => $value]);
        $image = $product->images()->orderBy('position')->get()->first(fn ($i) => $i->isAssigned() && $i->belongsToVariant($variant->id))?->url
            ?? $product->images()->orderBy('position')->value('url');
        $known = [
            'item_name' => $content?->effectiveTitle() ?? $product->title,
            'brand' => $product->vendor,
            'manufacturer' => $attrs['hersteller'] ?? $product->vendor,
            'product_description' => trim(strip_tags($content?->effectiveDescription() ?? (string) $product->description_html)),
            'bullet_point' => $content?->bullets[0] ?? null,
            'generic_keyword' => $content?->search_terms,
            'condition_type' => 'new_new',
            'country_of_origin' => $attrs['herkunftsland'] ?? null,
            'model_number' => $variant->model ?: $variant->sku,
            'part_number' => $variant->model ?: $variant->sku,
            'color' => $attrs['farbe'] ?? $variant->option1,
            'material' => $attrs['material'] ?? null,
            'number_of_items' => $attrs['anzahl'] ?? '1',
            'batteries_required' => 'false',
            'supplier_declared_dg_hz_regulation' => 'not_applicable',
            'main_product_image_locator' => ['media_location' => $image],
            'externally_assigned_product_identifier' => ['type' => 'ean', 'value' => $variant->barcode],
            'merchant_suggested_asin' => null,
        ];
        $out = [];
        foreach ($fields as $f) {
            $v = $known[$f['name']] ?? ($attrs[mb_strtolower($f['title'])] ?? null);
            if ($f['kind'] === 'group') {
                $out[$f['name']] = is_array($v) ? array_map(fn ($x) => (string) $x, $v) : [];
            } elseif ($f['kind'] === 'complex') {
                $out[$f['name']] = '';
            } else {
                $out[$f['name']] = is_array($v) ? '' : (string) $v;
            }
        }

        return $out;
    }

    /** Formularwerte in Amazon-Attribute umwandeln. */
    public function attributes(array $fields, array $values): array
    {
        $mp = $this->client->marketplaceId();
        $attributes = [];
        foreach ($fields as $f) {
            $raw = $values[$f['name']] ?? null;
            if ($f['kind'] === 'complex') {
                if (trim((string) $raw) === '') {
                    continue;
                }
                $decoded = json_decode((string) $raw, true);
                if (! is_array($decoded)) {
                    throw new MarketplaceException("„{$f['title']}“: bitte gültiges JSON eingeben.");
                }
                $attributes[$f['name']] = array_is_list($decoded) ? $decoded : [$decoded];

                continue;
            }
            $entry = [];
            if ($f['kind'] === 'simple') {
                if (trim((string) $raw) === '') {
                    continue;
                }
                $entry['value'] = $this->cast($raw, $f['parts'][0]['type']);
            } else {
                foreach ($f['parts'] as $part) {
                    $pv = $raw[$part['name']] ?? '';
                    if (trim((string) $pv) !== '') {
                        $entry[$part['name']] = $this->cast($pv, $part['type']);
                    }
                }
                if ($entry === []) {
                    continue;
                }
            }
            if ($f['marketplace']) {
                $entry['marketplace_id'] = $mp;
            }
            if ($f['language']) {
                $entry['language_tag'] = 'de_DE';
            }
            $attributes[$f['name']] = [$entry];
        }

        return $attributes;
    }

    private function cast(mixed $v, string $type): mixed
    {
        return match ($type) {
            'number' => (float) str_replace(',', '.', (string) $v),
            'integer' => (int) $v,
            'boolean' => in_array(strtolower((string) $v), ['1', 'true', 'ja'], true),
            default => trim((string) $v),
        };
    }

    /**
     * Produkt mit Angebot anlegen. Mit $preview prüft Amazon nur (VALIDATION_PREVIEW).
     *
     * @return array{accepted: bool, errors: list<string>, warnings: list<string>}
     */
    public function submit(Variant $variant, string $productType, array $attributes, bool $preview, string $fulfillment = 'FBM'): array
    {
        if (blank($variant->sku)) {
            throw new MarketplaceException('Die Variante braucht eine SKU.');
        }
        $mp = $this->client->marketplaceId();
        $channel = Channel::where('key', 'amazon')->firstOrFail();
        $price = $variant->priceOn($channel);
        $attributes['purchasable_offer'] = [[
            'marketplace_id' => $mp, 'currency' => 'EUR', 'audience' => 'ALL',
            'our_price' => [['schedule' => [['value_with_tax' => round($price, 2)]]]],
        ]];
        $attributes['fulfillment_availability'] = $fulfillment === 'FBA'
            ? [['fulfillment_channel_code' => 'AMAZON_EU']]
            : [['fulfillment_channel_code' => 'DEFAULT', 'quantity' => max(0, (int) $variant->stock)]];
        $attributes['condition_type'] ??= [['value' => 'new_new', 'marketplace_id' => $mp]];

        $query = ['marketplaceIds' => $mp, 'issueLocale' => 'de_DE'];
        if ($preview) {
            $query['mode'] = 'VALIDATION_PREVIEW';
        }
        $json = $this->client->request('PUT', '/listings/2021-08-01/items/'.$this->client->sellerId().'/'.rawurlencode($variant->sku), $query, [
            'productType' => $productType, 'requirements' => 'LISTING', 'attributes' => $attributes,
        ]);
        $issues = collect($json['issues'] ?? []);
        $errors = $issues->where('severity', 'ERROR')->map(fn ($i) => trim(($i['attributeNames'][0] ?? '').': '.$i['message'], ': '))->values()->all();
        $warnings = $issues->where('severity', 'WARNING')->pluck('message')->values()->all();
        $accepted = ($json['status'] ?? '') === ($preview ? 'VALID' : 'ACCEPTED') && $errors === [];

        if (! $preview && $accepted) {
            Listing::updateOrCreate(['variant_id' => $variant->id, 'channel_id' => $channel->id], [
                'sku' => $variant->sku, 'status' => 'pending', 'pushed_price' => $price, 'price_pushed_at' => now(),
                'created_by_us' => true, 'last_error' => null, 'checked_at' => now(),
                'data' => ['product_type' => $productType, 'fulfillment' => $fulfillment, 'submission_id' => $json['submissionId'] ?? null, 'new_asin' => true],
            ]);
        }

        return ['accepted' => $accepted, 'errors' => $errors, 'warnings' => $warnings];
    }
}
