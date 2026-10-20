<?php

namespace App\Livewire\Products;

use App\Models\ChangeLog;
use App\Models\Product;
use App\Models\Variant;
use App\Rules\Gtin;
use App\Support\Format;
use App\Support\OpenTabs;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Neuer Artikel')]
class Create extends Component
{
    public string $title = '';

    public string $vendor = '';

    public string $productType = '';

    public string $description = '';

    public string $tags = '';

    /** @var list<array{name: string, values: string}> bis zu drei Optionen, Werte mit Komma */
    public array $options = [['name' => '', 'values' => '']];

    /** @var array<string, array{sku: string, barcode: string, price: string}> je Kombination */
    public array $rows = [];

    public string $defaultPrice = '';

    /** @var list<string> Kombinationen, die es nicht gibt (z. B. „rot / klein“) */
    public array $excluded = [];

    /** @var array<string, array{sku: string, barcode: string, price: string}> Eingaben entfernter Zeilen, für das Wiederherstellen */
    public array $removedRows = [];

    public function removeRow(string $key): void
    {
        if (! array_key_exists($key, $this->rows)) {
            return;
        }
        if (count($this->rows) <= 1) {
            $this->addError('rows', 'Mindestens eine Variante muss bleiben.');

            return;
        }
        $this->excluded[] = $key;
        $this->syncRows();
    }

    public function restoreRow(string $key): void
    {
        $this->excluded = array_values(array_diff($this->excluded, [$key]));
        $this->syncRows();
    }

    public function restoreAll(): void
    {
        $this->excluded = [];
        $this->syncRows();
    }

    public function addOption(): void
    {
        if (count($this->options) < 3) {
            $this->options[] = ['name' => '', 'values' => ''];
        }
    }

    public function removeOption(int $i): void
    {
        unset($this->options[$i]);
        $this->options = array_values($this->options);
        $this->syncRows();
    }

    public function updated(string $prop): void
    {
        if (str_starts_with($prop, 'options') || $prop === 'defaultPrice') {
            $this->syncRows();
        }
    }

    /** @return list<list<string>> alle Kombinationen der Optionswerte */
    private function combinations(): array
    {
        $lists = collect($this->options)->map(fn ($o) => array_values(array_filter(array_map('trim', explode(',', $o['values'])))))
            ->filter(fn ($v, $i) => trim($this->options[$i]['name']) !== '' && $v)->values();
        if ($lists->isEmpty()) {
            return [[]];
        }
        $combos = [[]];
        foreach ($lists as $values) {
            $next = [];
            foreach ($combos as $c) {
                foreach ($values as $v) {
                    $next[] = [...$c, $v];
                }
            }
            $combos = $next;
        }

        return array_slice($combos, 0, 100);
    }

    /** @return array<string, list<string>> Anzeigename der Variante => Optionswerte */
    private function comboParts(): array
    {
        $parts = [];
        foreach ($this->combinations() as $combo) {
            $parts[implode(' / ', $combo) ?: 'Standard'] = $combo;
        }

        return $parts;
    }

    private function syncRows(): void
    {
        $keys = array_map(fn ($combo) => implode(' / ', $combo) ?: 'Standard', $this->combinations());
        // Ausschlüsse für Kombinationen, die es durch geänderte Werte nicht mehr gibt, verwerfen
        $this->excluded = array_values(array_intersect(array_unique($this->excluded), $keys));
        // Sind alle ausgeschlossen, bleibt die erste Kombination, damit es eine Variante gibt
        if ($keys && count($this->excluded) >= count($keys)) {
            $this->excluded = array_values(array_diff($this->excluded, [$keys[0]]));
        }
        $new = [];
        foreach ($keys as $key) {
            if (in_array($key, $this->excluded, true)) {
                // Eingaben aufheben, damit sie beim Wiederherstellen zurückkommen
                if (isset($this->rows[$key])) {
                    $this->removedRows[$key] = $this->rows[$key];
                }

                continue;
            }
            $new[$key] = $this->rows[$key] ?? $this->removedRows[$key] ?? ['sku' => '', 'barcode' => '', 'price' => $this->defaultPrice];
            unset($this->removedRows[$key]);
            if ($new[$key]['price'] === '' && $this->defaultPrice !== '') {
                $new[$key]['price'] = $this->defaultPrice;
            }
        }
        $this->rows = $new;
        // Nur Eingaben noch ausgeschlossener Kombinationen behalten
        $this->removedRows = array_intersect_key($this->removedRows, array_flip($this->excluded));
    }

    public function mount(): void
    {
        $this->syncRows();
    }

    public function save()
    {
        $this->syncRows();
        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'productType' => ['nullable', 'string', 'max:255'],
            'rows.*.sku' => ['required', 'string', 'max:255', 'distinct'],
            'rows.*.barcode' => ['nullable', new Gtin],
            'rows.*.price' => ['required', fn ($a, $v, $f) => Format::parseDecimal($v) === null ? $f('Bitte einen Preis eingeben.') : null],
        ], [
            'title.required' => 'Der Artikel braucht einen Titel.',
            'rows.*.sku.required' => 'Jede Variante braucht eine SKU.',
            'rows.*.sku.distinct' => 'SKUs müssen eindeutig sein.',
        ]);
        foreach ($this->rows as $key => $row) {
            if (Variant::where('sku', trim($row['sku']))->exists()) {
                $this->addError("rows.$key.sku", 'Diese SKU gibt es schon.');

                return null;
            }
        }

        $product = DB::transaction(function () {
            // Nur Werte übernehmen, die in einer verbliebenen Variante vorkommen (Reihenfolge wie eingegeben)
            // Teile aus den Kombinationen nehmen, nicht aus dem Namen: Werte dürfen selbst „ / “ enthalten
            $comboParts = $this->comboParts();
            $used = collect(array_keys($this->rows))->map(fn ($k) => $comboParts[$k] ?? []);
            // Gleicher Filter wie in combinations(), damit die Positionen zu den Varianten passen
            $opts = collect($this->options)->filter(fn ($o) => trim($o['name']) !== '' && array_filter(array_map('trim', explode(',', $o['values']))))->values()
                ->map(fn ($o, $i) => ['name' => trim($o['name']), 'position' => $i + 1, 'values' => array_values(array_filter(
                    array_map('trim', explode(',', $o['values'])),
                    fn ($v) => $v !== '' && $used->contains(fn ($parts) => ($parts[$i] ?? null) === $v)
                ))])->all();
            $product = Product::create([
                'title' => trim($this->title), 'vendor' => trim($this->vendor) ?: null, 'product_type' => trim($this->productType) ?: null,
                'description_html' => trim($this->description) ? '<p>'.nl2br(e(trim($this->description))).'</p>' : null,
                'tags' => array_values(array_filter(array_map('trim', explode(',', $this->tags)))) ?: null,
                'status' => 'DRAFT', 'options' => $opts, 'push_status' => Product::STATUS_NEW,
            ]);
            $pos = 1;
            foreach ($this->rows as $key => $row) {
                $parts = $comboParts[$key] ?? [];
                Variant::create([
                    'product_id' => $product->id, 'title' => $key === 'Standard' ? 'Default Title' : $key, 'position' => $pos++,
                    'sku' => trim($row['sku']), 'barcode' => trim($row['barcode']) ?: null, 'price' => Format::parseDecimal($row['price']),
                    'option1' => $parts[0] ?? null, 'option2' => $parts[1] ?? null, 'option3' => $parts[2] ?? null,
                ]);
            }
            ChangeLog::record($product, 'Angelegt', null, $product->title, 'user', null, auth()->id());

            return $product;
        });
        OpenTabs::open($product->id);
        session()->flash('flash', 'Artikel angelegt. Er ist noch nur in Marktschreier, mit „Speichern und zu Shopify“ wird er als Entwurf in Shopify angelegt.');

        return $this->redirect(route('products.edit', $product), navigate: true);
    }

    public function render()
    {
        return view('livewire.products.create');
    }
}
