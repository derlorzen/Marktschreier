<?php

namespace App\Livewire;

use App\Models\Channel;
use App\Models\Listing;
use App\Models\MarketplaceConnection;
use App\Models\PriceWatch;
use App\Models\Variant;
use App\Services\Pricing\BuyBoxWatcher;
use App\Support\Format;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Preiswächter')]
class PriceWatches extends Component
{
    public string $search = '';

    public ?int $editId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public ?string $notice = null;

    public ?string $error = null;

    public function startNew(int $variantId, BuyBoxWatcher $watcher): void
    {
        $variant = Variant::findOrFail($variantId);
        $amazon = Channel::where('key', 'amazon')->first();
        $this->editId = 0;
        $this->form = [
            'variant_id' => $variantId,
            'strategy' => 'beat',
            'min_price' => Format::decimalForInput($watcher->suggestMinPrice($variant)),
            'max_price' => Format::decimalForInput(round($variant->priceOn($amazon) * 1.3, 2)),
            'auto' => false,
            'max_daily_change' => 20,
        ];
        $this->search = '';
    }

    public function edit(int $id): void
    {
        $w = PriceWatch::findOrFail($id);
        $this->editId = $id;
        $this->form = [
            'variant_id' => $w->variant_id, 'strategy' => $w->strategy, 'min_price' => Format::decimalForInput($w->min_price),
            'max_price' => Format::decimalForInput($w->max_price), 'auto' => $w->auto, 'max_daily_change' => $w->max_daily_change,
        ];
    }

    public function save(): void
    {
        $this->validate([
            'form.strategy' => ['required', 'in:beat,match,watch'],
            'form.min_price' => ['required', fn ($a, $v, $f) => Format::parseDecimal($v) === null || Format::parseDecimal($v) <= 0 ? $f('Bitte einen Mindestpreis angeben.') : null],
            'form.max_price' => ['nullable', fn ($a, $v, $f) => $v !== '' && $v !== null && Format::parseDecimal($v) === null ? $f('Ungültiger Höchstpreis.') : null],
            'form.max_daily_change' => ['required', 'integer', 'between:1,100'],
        ]);
        $min = (float) Format::parseDecimal($this->form['min_price']);
        $max = Format::parseDecimal($this->form['max_price'] ?? '');
        if ($max !== null && (float) $max < $min) {
            $this->addError('form.max_price', 'Der Höchstpreis liegt unter dem Mindestpreis.');

            return;
        }
        PriceWatch::updateOrCreate(['variant_id' => $this->form['variant_id']], [
            'strategy' => $this->form['strategy'], 'min_price' => $min, 'max_price' => $max,
            'auto' => (bool) $this->form['auto'], 'max_daily_change' => (int) $this->form['max_daily_change'], 'active' => true,
        ]);
        $this->reset('editId', 'form');
        $this->notice = 'Gespeichert. Geprüft wird alle 30 Minuten oder sofort mit „Jetzt prüfen“.';
    }

    public function toggle(int $id): void
    {
        $w = PriceWatch::findOrFail($id);
        $w->update(['active' => ! $w->active]);
    }

    public function remove(int $id): void
    {
        PriceWatch::whereKey($id)->delete();
        $this->notice = 'Wächter entfernt. Der Preis bleibt, wie er ist.';
    }

    public function apply(int $id, BuyBoxWatcher $watcher): void
    {
        $ok = $watcher->applySuggestion(PriceWatch::with('variant')->findOrFail($id), auth()->id());
        $this->notice = $ok ? 'Preis übernommen und zu Amazon übertragen.' : null;
        $this->error = $ok ? null : 'Übertragung fehlgeschlagen, Details im Artikel unter Marktplätze.';
    }

    public function checkNow(BuyBoxWatcher $watcher): void
    {
        try {
            $s = $watcher->run();
            $this->notice = "{$s['checked']} geprüft, {$s['applied']} automatisch angepasst, {$s['suggested']} Vorschläge.";
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render()
    {
        $amazon = Channel::where('key', 'amazon')->first();
        $candidates = collect();
        if (trim($this->search) !== '' && $amazon) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';
            $candidates = Variant::with('product:id,title,vendor')
                ->whereHas('listings', fn ($l) => $l->where('channel_id', $amazon->id)->whereNotNull('external_id'))
                ->whereDoesntHave('priceWatch')
                ->where(fn ($q) => $q->where('sku', 'like', $term)->orWhereHas('product', fn ($p) => $p->where('title', 'like', $term)->orWhere('vendor', 'like', $term)))
                ->limit(10)->get();
        }

        return view('livewire.price-watches', [
            'watches' => PriceWatch::with('variant.product', 'variant.channelPrices')->get()->sortBy(fn ($w) => [($w->last_result['won'] ?? true) ? 1 : 0, $w->variant->product->title])->values(),
            'candidates' => $candidates,
            'amazon' => $amazon,
            'connected' => MarketplaceConnection::for('amazon')->isConnected(),
            'editVariant' => $this->editId !== null ? Variant::with('product')->find($this->form['variant_id'] ?? 0) : null,
        ]);
    }
}
