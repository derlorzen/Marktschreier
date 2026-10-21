<?php

namespace App\Livewire\Products;

use App\Models\Channel;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Variant;
use App\Rules\Gtin;
use App\Services\ProductEditor;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Eine Variante im Detail, aufgebaut wie die Variantenansicht in PlentyONE:
 * Einstellungen, Merkmale (geerbt oder eigen), Bilder und Marktplätze.
 */
class VariantDetail extends Component
{
    public Product $product;

    public int $variantId;

    public string $section = 'einstellungen';

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var list<array{key: string, value: string}> eigene Werte je Merkmal, leer = vom Artikel */
    public array $attrs = [];

    public array $newAttr = ['key' => '', 'value' => ''];

    /** @var list<int> */
    public array $imageIds = [];

    public ?string $notice = null;

    public function mount(Product $product, int $variantId): void
    {
        $this->product = $product;
        $this->variantId = $variantId;
        $this->loadVariant();
    }

    private function variant(): Variant
    {
        return Variant::with(['product.attributeValues', 'attributeValues', 'listings.channel', 'channelPrices'])
            ->where('product_id', $this->product->id)->findOrFail($this->variantId);
    }

    private function loadVariant(): void
    {
        $v = $this->variant();
        $this->form = [
            'sku' => (string) $v->sku,
            'barcode' => (string) $v->barcode,
            'asin' => (string) $v->asin,
            'model' => (string) $v->model,
            'active' => (bool) $v->active,
            'weight_g' => $v->weight_g === null ? '' : (string) $v->weight_g,
            'length_mm' => $v->length_mm === null ? '' : (string) $v->length_mm,
            'width_mm' => $v->width_mm === null ? '' : (string) $v->width_mm,
            'height_mm' => $v->height_mm === null ? '' : (string) $v->height_mm,
        ];
        $own = $v->attributeValues->pluck('value', 'key');
        $this->attrs = [];
        foreach (array_keys($v->effectiveAttributes()) as $key) {
            $this->attrs[] = ['key' => (string) $key, 'value' => (string) ($own[$key] ?? '')];
        }
        $this->imageIds = $this->product->images()->get()
            ->filter(fn ($img) => in_array($v->id, $img->variant_ids ?? [], true))->pluck('id')->values()->all();
    }

    public function save(ProductEditor $editor): void
    {
        $v = $this->variant();
        $int = ['nullable', 'integer', 'min:0', 'max:1000000'];
        $this->form['asin'] = strtoupper(trim((string) $this->form['asin']));
        $this->validate([
            'form.sku' => ['nullable', 'string', 'max:255', Rule::unique('variants', 'sku')->ignore($v->id)],
            'form.barcode' => ['nullable', 'string', new Gtin],
            'form.asin' => ['nullable', fn ($a, $value, $fail) => $value !== '' && ! Variant::validAsin($value) ? $fail('Eine ASIN hat 10 Zeichen, meist beginnend mit B0.') : null],
            'form.model' => ['nullable', 'string', 'max:255'],
            'form.weight_g' => $int, 'form.length_mm' => $int, 'form.width_mm' => $int, 'form.height_mm' => $int,
        ], ['form.sku.unique' => 'Diese SKU ist bereits an einem anderen Artikel vergeben.']);

        $fields = [
            'sku' => trim($this->form['sku']), 'barcode' => trim($this->form['barcode']),
            'asin' => $this->form['asin'], 'model' => trim($this->form['model']),
            'active' => (bool) $this->form['active'],
        ];
        foreach (['weight_g', 'length_mm', 'width_mm', 'height_mm'] as $f) {
            $fields[$f] = $this->form[$f] === '' || $this->form[$f] === null ? null : (int) $this->form[$f];
        }
        $count = $editor->update($this->product, [], [$v->id => $fields], auth()->id());
        $this->done($count);
    }

    public function saveAttributes(ProductEditor $editor): void
    {
        $this->validate(['attrs.*.value' => ['nullable', 'string', 'max:2000']]);
        $values = collect($this->attrs)->mapWithKeys(fn ($a) => [$a['key'] => $a['value']])->all();
        $this->done($editor->setVariantAttributes($this->variant(), $values, auth()->id()));
    }

    public function addAttribute(ProductEditor $editor): void
    {
        $this->validate([
            'newAttr.key' => ['required', 'string', 'max:100'],
            'newAttr.value' => ['required', 'string', 'max:2000'],
        ], ['newAttr.key.required' => 'Merkmal braucht einen Namen.', 'newAttr.value.required' => 'Bitte einen Wert angeben.']);
        $count = $editor->setVariantAttributes($this->variant(), [trim($this->newAttr['key']) => $this->newAttr['value']], auth()->id());
        $this->reset('newAttr');
        $this->done($count);
    }

    public function saveImages(ProductEditor $editor): void
    {
        $this->done($editor->setVariantImages($this->variant(), array_map('intval', $this->imageIds), auth()->id()));
    }

    public function makeMain(ProductEditor $editor): void
    {
        $this->done($editor->setMainVariant($this->product, $this->variant(), auth()->id()) ? 1 : 0, 'Ist jetzt die Hauptvariante.');
    }

    private function done(int $count, ?string $text = null): void
    {
        $this->resetErrorBag();
        $this->loadVariant();
        $this->notice = $count ? ($text ?? $count.' '.($count === 1 ? 'Änderung' : 'Änderungen').' gespeichert.') : 'Keine Änderungen.';
        $this->dispatch('variant-saved');
    }

    public function render()
    {
        $v = $this->variant();
        $inherited = $v->product->attributeValues->pluck('value', 'key');

        return view('livewire.products.variant-detail', [
            'variant' => $v,
            'inherited' => $inherited,
            'images' => $this->product->images()->get(),
            'channels' => Channel::active()->get(),
            'vat' => (float) Setting::get('billbee.vat_rate', 19),
        ]);
    }
}
