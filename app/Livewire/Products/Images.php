<?php

namespace App\Livewire\Products;

use App\Models\Product;
use App\Services\Shopify\CatalogWriter;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Reiter „Bilder“: hochladen, sortieren, Alt-Text, löschen. Bilder liegen in Shopify. */
class Images extends Component
{
    use WithFileUploads;

    public Product $product;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploads = [];

    public array $alts = [];

    public ?string $notice = null;

    public ?string $error = null;

    public function mount(Product $product): void
    {
        $this->product = $product;
        $this->loadAlts();
    }

    private function loadAlts(): void
    {
        $this->alts = $this->product->images()->get()->mapWithKeys(fn ($i) => [$i->id => (string) $i->alt])->all();
    }

    private function attempt(callable $fn, string $ok): void
    {
        try {
            $fn();
            $this->notice = $ok;
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
        $this->product->refresh();
        $this->loadAlts();
    }

    public function upload(CatalogWriter $writer): void
    {
        $this->validate(['uploads' => ['required', 'array', 'max:10'], 'uploads.*' => ['image', 'mimes:jpg,jpeg,png,webp,gif', 'max:20480']], [
            'uploads.*.image' => 'Nur Bilder (JPG, PNG, WebP, GIF).', 'uploads.*.max' => 'Ein Bild darf höchstens 20 MB groß sein.',
        ]);
        $files = $this->uploads;
        $this->attempt(function () use ($writer, $files) {
            foreach ($files as $file) {
                $writer->uploadImage($this->product, $file->getRealPath(), $file->getClientOriginalName(), $file->getMimeType(), $this->product->title);
            }
        }, count($files).' '.(count($files) === 1 ? 'Bild' : 'Bilder').' hochgeladen.');
        $this->uploads = [];
    }

    public function move(int $imageId, int $direction, CatalogWriter $writer): void
    {
        $ids = $this->product->images()->pluck('shopify_id', 'id')->all();
        $order = array_keys($ids);
        $pos = array_search($imageId, $order, true);
        $target = $pos + $direction;
        if ($pos === false || $target < 0 || $target >= count($order)) {
            return;
        }
        [$order[$pos], $order[$target]] = [$order[$target], $order[$pos]];
        $this->attempt(fn () => $writer->reorderImages($this->product, array_map(fn ($id) => $ids[$id], $order)), 'Reihenfolge gespeichert.');
    }

    public function saveAlt(int $imageId, CatalogWriter $writer): void
    {
        $image = $this->product->images()->findOrFail($imageId);
        $this->attempt(fn () => $writer->updateAlt($this->product, $image->shopify_id, mb_substr(trim($this->alts[$imageId] ?? ''), 0, 512)), 'Alt-Text gespeichert.');
    }

    public function delete(int $imageId, CatalogWriter $writer): void
    {
        $image = $this->product->images()->findOrFail($imageId);
        $this->attempt(fn () => $writer->deleteImage($this->product, $image->shopify_id), 'Bild entfernt.');
    }

    public function render()
    {
        return view('livewire.products.images', ['images' => $this->product->images()->get()]);
    }
}
