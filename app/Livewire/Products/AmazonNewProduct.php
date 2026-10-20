<?php

namespace App\Livewire\Products;

use App\Models\Variant;
use App\Services\Marketplaces\Amazon\AmazonProductBuilder;
use Livewire\Component;

/** Neues Produkt mit eigener ASIN bei Amazon anlegen, Felder aus der Produkttyp-Definition. */
class AmazonNewProduct extends Component
{
    public int $variantId;

    public string $typeQuery = '';

    public array $types = [];

    public string $productType = '';

    public array $fields = [];

    public array $values = [];

    public ?array $check = null;

    public ?string $error = null;

    public ?string $done = null;

    public function mount(int $variantId): void
    {
        $this->variantId = $variantId;
        $v = Variant::with('product')->findOrFail($variantId);
        $this->typeQuery = $v->product->product_type ?: $v->product->title;
    }

    public function searchTypes(AmazonProductBuilder $builder): void
    {
        try {
            $this->types = $builder->searchProductTypes($this->typeQuery);
            $this->error = $this->types ? null : 'Kein Produkttyp gefunden, anderen Begriff versuchen (z. B. „Kartenhülle“, „Aufbewahrung“).';
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function pickType(string $name, AmazonProductBuilder $builder): void
    {
        try {
            $this->productType = $name;
            $this->fields = $builder->requiredFields($name);
            $variant = Variant::with('product.attributeValues')->findOrFail($this->variantId);
            $this->values = $builder->prefill($variant, $this->fields);
            $this->check = null;
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    private function run(AmazonProductBuilder $builder, bool $preview): ?array
    {
        try {
            $variant = Variant::with('product')->findOrFail($this->variantId);
            $fulfillment = (string) app(\App\Services\Marketplaces\Amazon\AmazonClient::class)->connection()->setting('fulfillment', 'FBM');

            return $builder->submit($variant, $this->productType, $builder->attributes($this->fields, $this->values), $preview, $fulfillment);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();

            return null;
        }
    }

    public function validateWithAmazon(AmazonProductBuilder $builder): void
    {
        $this->error = null;
        $this->check = $this->run($builder, true);
    }

    public function create(AmazonProductBuilder $builder): void
    {
        if (! ($this->check['accepted'] ?? false)) {
            $this->error = 'Bitte zuerst prüfen lassen, bis Amazon keine Fehler mehr meldet.';

            return;
        }
        $r = $this->run($builder, false);
        if ($r && $r['accepted']) {
            $this->done = 'An Amazon übergeben. Die ASIN erscheint nach dem nächsten Lesen der Angebote, das kann bis zu einer Stunde dauern.';
        } elseif ($r) {
            $this->check = $r;
        }
    }

    public function render()
    {
        return view('livewire.products.amazon-new-product', ['variant' => Variant::with('product')->find($this->variantId)]);
    }
}
