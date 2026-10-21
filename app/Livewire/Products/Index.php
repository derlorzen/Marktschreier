<?php

namespace App\Livewire\Products;

use App\Jobs\PushProductToShopify;
use App\Models\Product;
use App\Models\Variant;
use App\Services\ProductEditor;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Artikel')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $vendor = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $sync = '';

    /** Fehlende Daten: gtin | cost | sku */
    #[Url(except: '')]
    public string $missing = '';

    #[Url(except: 'title')]
    public string $sort = 'title';

    #[Url(except: 'asc')]
    public string $dir = 'asc';

    public int $perPage = 50;

    /** @var list<int> */
    public array $selected = [];

    public bool $selectAllMatching = false;

    /** Gruppenfunktion */
    public string $bulkAction = '';

    public string $bulkValue = '';

    public ?string $notice = null;

    /** @var list<int> aufgeklappte Artikel, deren Varianten (Kinder) sichtbar sind */
    public array $expanded = [];

    public ?int $bulkRuleId = null;

    /** @var list<int> */
    public array $bulkRuleChannels = [];

    public bool $bulkOnlyIncrease = false;

    /** Zusammenfassung der Vorschau, bevor ein Regelwerk auf viele Artikel angewendet wird. */
    public ?array $bulkPreview = null;

    public function mount(): void
    {
        $this->bulkRuleId = \App\Models\PriceRule::orderBy('name')->value('id');
        $this->bulkRuleChannels = \App\Models\Channel::active()->pluck('id')->all();
    }

    public function toggleExpand(int $id): void
    {
        $this->expanded = in_array($id, $this->expanded, true)
            ? array_values(array_diff($this->expanded, [$id]))
            : [...$this->expanded, $id];
    }

    public function updatedBulkAction(): void
    {
        $this->bulkPreview = null;
    }

    private function bulkRuleRows(): array
    {
        $rule = \App\Models\PriceRule::with('lines')->find($this->bulkRuleId);
        if (! $rule) {
            return [];
        }
        $variants = \App\Models\Variant::with(['product', 'channelPrices'])->whereIn('product_id', $this->targetIds())->get();
        $channels = \App\Models\Channel::active()->whereIn('id', $this->bulkRuleChannels)->get();

        return app(\App\Services\Pricing\PriceRuleApplier::class)->preview($variants, $rule, $channels, $this->bulkOnlyIncrease);
    }

    public function previewBulkRule(): void
    {
        if ($this->bulkRuleChannels === []) {
            $this->addError('bulkValue', 'Bitte mindestens einen Kanal anhaken.');

            return;
        }
        $rows = $this->bulkRuleRows();
        $sum = app(\App\Services\Pricing\PriceRuleApplier::class)->summarize($rows);
        $reasons = collect($rows)->whereNotNull('skip')->countBy('skip')->all();
        $this->bulkPreview = $sum + ['reasons' => $reasons];
    }

    private const SORTS = ['title' => 'title', 'vendor' => 'vendor', 'updated' => 'updated_at', 'stock' => 'variants_sum_stock', 'price' => 'variants_min_price'];

    public function updated(string $prop): void
    {
        if (str_starts_with($prop, 'selected') || in_array($prop, ['bulkRuleId', 'selectAllMatching'], true)) {
            $this->bulkPreview = null;
        }
        if (in_array($prop, ['search', 'vendor', 'type', 'status', 'sync', 'missing'], true)) {
            $this->resetPage();
            $this->clearSelection();
        }
    }

    public function sortBy(string $key): void
    {
        if (! isset(self::SORTS[$key])) {
            return;
        }
        $this->dir = $this->sort === $key && $this->dir === 'asc' ? 'desc' : 'asc';
        $this->sort = $key;
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'vendor', 'type', 'status', 'sync', 'missing');
        $this->resetPage();
        $this->clearSelection();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->selectAllMatching = false;
    }

    /** Alle Artikel der aktuellen Seite an- oder abwählen. */
    public function togglePage(array $ids): void
    {
        $ids = array_map('intval', $ids);
        $this->selectAllMatching = false;
        $this->selected = array_values(array_diff($ids, $this->selected) === []
            ? array_diff($this->selected, $ids)
            : array_unique(array_merge($this->selected, $ids)));
    }

    protected function query(): Builder
    {
        $q = Product::query()->search($this->search);
        if ($this->vendor !== '') {
            $q->where('vendor', $this->vendor);
        }
        if ($this->type !== '') {
            $q->where('product_type', $this->type);
        }
        if ($this->status !== '') {
            $q->where('status', $this->status);
        }
        if ($this->sync === 'open') {
            $q->whereIn('push_status', [Product::STATUS_PENDING, Product::STATUS_ERROR, Product::STATUS_CONFLICT]);
        } elseif ($this->sync !== '') {
            $q->where('push_status', $this->sync);
        }
        match ($this->missing) {
            'gtin' => $q->whereHas('variants', fn ($v) => $v->where(fn ($w) => $w->whereNull('barcode')->orWhere('barcode', ''))),
            'sku' => $q->whereHas('variants', fn ($v) => $v->where(fn ($w) => $w->whereNull('sku')->orWhere('sku', ''))),
            'cost' => $q->whereHas('variants', fn ($v) => $v->whereNull('cost_net')),
            'billbee' => $q->whereHas('variants', fn ($v) => $v->whereNull('billbee_id')),
            default => null,
        };

        return $q;
    }

    /** @return list<int> */
    protected function targetIds(): array
    {
        return $this->selectAllMatching ? $this->query()->pluck('id')->all() : $this->selected;
    }

    public function runBulk(ProductEditor $editor): void
    {
        $ids = $this->targetIds();
        if ($ids === [] || $this->bulkAction === '') {
            return;
        }
        $value = trim($this->bulkValue);
        $needsValue = in_array($this->bulkAction, ['tag_add', 'tag_remove', 'vendor', 'type', 'status'], true);
        if ($needsValue && $value === '') {
            $this->addError('bulkValue', 'Bitte einen Wert angeben.');

            return;
        }

        if ($this->bulkAction === 'price_rule') {
            $rule = \App\Models\PriceRule::with('lines')->find($this->bulkRuleId);
            if (! $rule || $this->bulkPreview === null) {
                return;
            }
            $count = app(\App\Services\Pricing\PriceRuleApplier::class)->apply($this->bulkRuleRows(), $rule, auth()->id());
            $this->notice = "Regelwerk „{$rule->name}“ angewendet: {$count} Preise geändert. Shopify-Preise sind als offene Änderung vorgemerkt.";
            $this->reset('bulkAction', 'bulkPreview');

            return;
        }

        if ($this->bulkAction === 'push') {
            $n = 0;
            foreach (Product::whereIn('id', $ids)->whereIn('push_status', [Product::STATUS_PENDING, Product::STATUS_ERROR, Product::STATUS_CONFLICT])->pluck('id') as $id) {
                PushProductToShopify::dispatch($id, auth()->id());
                $n++;
            }
            $this->notice = $n ? "{$n} Artikel werden zu Shopify übertragen. Den Fortschritt siehst du unter Abgleich." : 'Keine offenen Änderungen in der Auswahl.';
            $this->reset('bulkAction', 'bulkValue');

            return;
        }

        $changed = 0;
        foreach (Product::with('variants')->whereIn('id', $ids)->cursor() as $product) {
            $fields = match ($this->bulkAction) {
                'tag_add' => ['tags' => array_values(array_unique(array_merge($product->tags ?? [], [$value])))],
                'tag_remove' => ['tags' => array_values(array_filter($product->tags ?? [], fn ($t) => mb_strtolower($t) !== mb_strtolower($value)))],
                'vendor' => ['vendor' => $value],
                'type' => ['product_type' => $value],
                'status' => ['status' => $value],
                default => [],
            };
            if ($editor->update($product, $fields, [], auth()->id()) > 0) {
                $changed++;
            }
        }
        $this->reset('bulkAction', 'bulkValue');
        $this->notice = "{$changed} von ".count($ids).' Artikeln geändert. Noch nicht in Shopify: über die Gruppenfunktion „Zu Shopify übertragen“ oder im Artikel freigeben.';
    }

    public function render()
    {
        $column = self::SORTS[$this->sort] ?? 'title';
        $products = $this->query()
            ->with(['images' => fn ($q) => $q->where('position', 1), 'variants:id,product_id,sku,barcode,asin,price,cost_net,stock,is_main,active,position,title,option1,option2,option3'])
            ->withCount('variants')
            ->withSum('variants', 'stock')
            ->withMin('variants', 'price')
            ->withMax('variants', 'price')
            ->orderBy($column, $this->dir === 'desc' ? 'desc' : 'asc')
            ->orderBy('id')
            ->paginate($this->perPage);

        return view('livewire.products.index', [
            'products' => $products,
            'vendors' => Product::whereNotNull('vendor')->where('vendor', '!=', '')->distinct()->orderBy('vendor')->pluck('vendor'),
            'types' => Product::whereNotNull('product_type')->where('product_type', '!=', '')->distinct()->orderBy('product_type')->pluck('product_type'),
            'total' => Product::count(),
            'priceRules' => \App\Models\PriceRule::orderBy('name')->get(['id', 'name']),
            'channels' => \App\Models\Channel::active()->get(),
            'selectedCount' => $this->selectAllMatching ? $products->total() : count($this->selected),
            'filtered' => $this->search !== '' || $this->vendor !== '' || $this->type !== '' || $this->status !== '' || $this->sync !== '' || $this->missing !== '',
        ]);
    }
}
