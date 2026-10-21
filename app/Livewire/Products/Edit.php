<?php

namespace App\Livewire\Products;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Setting;
use App\Rules\Gtin;
use App\Services\ProductEditor;
use App\Services\Shopify\ProductPusher;
use App\Support\Format;
use App\Support\OpenTabs;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class Edit extends Component
{
    public Product $product;

    #[Url(except: 'stamm')]
    public string $tab = 'stamm';

    /** Geöffnete Variante im Reiter Varianten, wie die Variantenansicht in PlentyONE. */
    #[Url(as: 'variante')]
    public ?int $openVariant = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var array<int, array<string, string>> */
    public array $variants = [];

    /** @var list<array{key: string, value: string}> */
    public array $attrs = [];

    public ?string $notice = null;

    /** Preise je Kanal: eigene Preise für Marktplätze [variant_id][channel_id] */
    public array $channelPriceInputs = [];

    public ?int $ruleId = null;

    /** @var list<int> */
    public array $ruleChannels = [];

    public bool $onlyIncrease = false;

    public bool $showRulePreview = false;

    public bool $showAddVariant = false;

    public array $newVariant = ['options' => [], 'sku' => '', 'barcode' => '', 'price' => ''];

    public ?string $noticeType = null;

    public function openVariantDetail(int $id): void
    {
        $this->openVariant = $this->openVariant === $id ? null : $id;
        $this->tab = 'varianten';
    }

    #[\Livewire\Attributes\On('variant-saved')]
    public function variantSaved(): void
    {
        $this->fillForm();
    }

    public function mount(Product $product): void
    {
        $this->product = $product;
        OpenTabs::open($product->id);
        $this->fillForm();
    }

    protected function fillForm(): void
    {
        $p = $this->product->fresh(['variants', 'attributeValues']);
        if ($this->openVariant && ! $p->variants->contains('id', $this->openVariant)) {
            $this->openVariant = null;
        }
        $this->product = $p;
        $this->form = [
            'title' => $p->title,
            'description_html' => (string) $p->description_html,
            'vendor' => (string) $p->vendor,
            'product_type' => (string) $p->product_type,
            'tags' => implode(', ', $p->tags ?? []),
            'status' => $p->status,
            'seo_title' => (string) $p->seo_title,
            'seo_description' => (string) $p->seo_description,
        ];
        $this->variants = [];
        foreach ($p->variants as $v) {
            $this->variants[$v->id] = [
                'price' => Format::decimalForInput($v->price),
                'compare_at_price' => Format::decimalForInput($v->compare_at_price),
                'sku' => (string) $v->sku,
                'barcode' => (string) $v->barcode,
            ];
        }
        $this->channelPriceInputs = [];
        foreach ($p->variants()->with('channelPrices')->get() as $v) {
            foreach ($v->channelPrices as $cp) {
                $this->channelPriceInputs[$v->id][$cp->channel_id] = Format::decimalForInput($cp->price);
            }
        }
        $this->ruleId ??= \App\Models\PriceRule::orderBy('name')->value('id');
        if ($this->ruleChannels === []) {
            $this->ruleChannels = \App\Models\Channel::active()->pluck('id')->all();
        }
        $this->attrs = $p->attributeValues->map(fn ($a) => ['key' => $a->key, 'value' => (string) $a->value])->values()->all();
    }

    protected function rules(): array
    {
        return [
            'form.title' => ['required', 'string', 'max:255'],
            'form.description_html' => ['nullable', 'string', 'max:65000'],
            'form.vendor' => ['nullable', 'string', 'max:255'],
            'form.product_type' => ['nullable', 'string', 'max:255'],
            'form.tags' => ['nullable', 'string', 'max:2000'],
            'form.status' => ['required', Rule::in(['ACTIVE', 'DRAFT', 'ARCHIVED', 'UNLISTED'])],
            'form.seo_title' => ['nullable', 'string', 'max:255'],
            'form.seo_description' => ['nullable', 'string', 'max:1000'],
            'variants.*.price' => ['required', function ($attr, $value, $fail) {
                $n = Format::parseDecimal($value);
                if ($n === null || (float) $n < 0) {
                    $fail('Bitte einen gültigen Preis eingeben, z. B. 12,90.');
                }
            }],
            'variants.*.compare_at_price' => ['nullable', function ($attr, $value, $fail) {
                if ($value !== '' && $value !== null && Format::parseDecimal($value) === null) {
                    $fail('Bitte einen gültigen Streichpreis eingeben oder leer lassen.');
                }
            }],
            'variants.*.sku' => ['nullable', 'string', 'max:255'],
            'variants.*.barcode' => ['nullable', 'string', new Gtin],
            'attrs.*.key' => ['required', 'string', 'max:100', 'distinct'],
            'attrs.*.value' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function messages(): array
    {
        return [
            'form.title.required' => 'Der Titel darf nicht leer sein.',
            'attrs.*.key.required' => 'Merkmal braucht einen Namen.',
            'attrs.*.key.distinct' => 'Dieses Merkmal gibt es schon.',
        ];
    }

    public function save(ProductEditor $editor): int
    {
        $this->validate();

        // Doppelte SKUs führen beim Billbee-Abgleich zu Fehlzuordnungen.
        foreach ($this->variants as $id => $v) {
            $sku = trim($v['sku']);
            if ($sku !== '' && \App\Models\Variant::where('sku', $sku)->where('id', '!=', $id)->exists()) {
                $this->addError("variants.$id.sku", 'Diese SKU ist bereits an einem anderen Artikel vergeben.');

                return -1;
            }
        }

        $productFields = [
            'title' => trim($this->form['title']),
            'description_html' => $this->form['description_html'],
            'vendor' => trim($this->form['vendor']),
            'product_type' => trim($this->form['product_type']),
            'tags' => array_values(array_filter(array_map('trim', explode(',', $this->form['tags'])), fn ($t) => $t !== '')),
            'status' => $this->form['status'],
            'seo_title' => trim($this->form['seo_title']),
            'seo_description' => trim($this->form['seo_description']),
        ];
        $variantFields = [];
        foreach ($this->variants as $id => $v) {
            $variantFields[$id] = [
                'price' => Format::parseDecimal($v['price']),
                'compare_at_price' => Format::parseDecimal($v['compare_at_price']),
                'sku' => trim($v['sku']),
                'barcode' => trim($v['barcode']),
            ];
        }

        $count = $editor->update($this->product, $productFields, $variantFields, auth()->id(), resolvesConflict: true);
        $count += $this->saveAttributes();
        $fresh = $this->product->fresh('variants');
        if ($fresh->push_status !== Product::STATUS_CONFLICT) {
            $fresh->refreshPushStatus();
            $fresh->save();
        }

        $this->fillForm();
        $this->notice = $count ? $count.' '.($count === 1 ? 'Änderung' : 'Änderungen').' gespeichert. Noch nicht in Shopify.' : 'Keine Änderungen.';
        $this->noticeType = 'ok';

        return $count;
    }

    private function saveAttributes(): int
    {
        return DB::transaction(function () {
            $count = 0;
            $existing = $this->product->attributeValues()->get()->keyBy('key');
            $keep = [];
            foreach ($this->attrs as $row) {
                $key = trim($row['key']);
                $value = trim((string) $row['value']);
                $keep[] = $key;
                $old = $existing->get($key);
                if (! $old) {
                    ProductAttribute::create(['product_id' => $this->product->id, 'key' => $key, 'value' => $value]);
                    \App\Models\ChangeLog::record($this->product, 'Merkmal: '.$key, null, $value, 'user', null, auth()->id());
                    $count++;
                } elseif ((string) $old->value !== $value) {
                    \App\Models\ChangeLog::record($this->product, 'Merkmal: '.$key, $old->value, $value, 'user', null, auth()->id());
                    $old->update(['value' => $value]);
                    $count++;
                }
            }
            foreach ($existing as $key => $attr) {
                if (! in_array($key, $keep, true)) {
                    \App\Models\ChangeLog::record($this->product, 'Merkmal: '.$key, $attr->value, null, 'user', null, auth()->id());
                    $attr->delete();
                    $count++;
                }
            }

            return $count;
        });
    }

    public function addAttribute(): void
    {
        $this->attrs[] = ['key' => '', 'value' => ''];
    }

    public function removeAttribute(int $index): void
    {
        unset($this->attrs[$index]);
        $this->attrs = array_values($this->attrs);
    }

    /** Speichern und sofort zu Shopify übertragen. */
    public function push(ProductEditor $editor, ProductPusher $pusher): void
    {
        if ($this->save($editor) < 0 || $this->getErrorBag()->isNotEmpty()) {
            return;
        }
        $product = $this->product->fresh('variants');
        if (! $product->hasPendingChanges()) {
            $this->notice = 'Alles ist bereits in Shopify, nichts zu übertragen.';
            $this->noticeType = 'ok';

            return;
        }
        $wasNew = ! $product->shopify_id;
        $ok = $pusher->push($product, auth()->id());
        $this->fillForm();
        $this->notice = $ok ? ($wasNew ? 'In Shopify als Entwurf angelegt.' : 'Zu Shopify übertragen.') : 'Übertragung fehlgeschlagen: '.$this->product->push_error;
        $this->noticeType = $ok ? 'ok' : 'bad';
    }

    public function saveChannelPrices(ProductEditor $editor): void
    {
        $channels = \App\Models\Channel::active()->get()->reject->isShopify();
        $count = 0;
        foreach ($this->product->variants as $variant) {
            foreach ($channels as $channel) {
                $raw = $this->channelPriceInputs[$variant->id][$channel->id] ?? '';
                $value = Format::parseDecimal($raw);
                if (trim((string) $raw) !== '' && ($value === null || (float) $value <= 0)) {
                    $this->addError("channelPriceInputs.{$variant->id}.{$channel->id}", 'Ungültiger Preis');

                    return;
                }
                $count += $editor->setChannelPrice($variant, $channel, $value, auth()->id()) ? 1 : 0;
            }
        }
        $this->fillForm();
        $this->notice = $count ? $count.' Kanalpreise gespeichert.' : 'Keine Änderungen.';
        $this->noticeType = 'ok';
    }

    private function rulePreviewRows(): array
    {
        $rule = $this->ruleId ? \App\Models\PriceRule::with('lines')->find($this->ruleId) : null;
        if (! $rule) {
            return [];
        }
        $channels = \App\Models\Channel::active()->whereIn('id', $this->ruleChannels)->get();
        $variants = $this->product->variants()->with(['channelPrices', 'product'])->get();

        return app(\App\Services\Pricing\PriceRuleApplier::class)->preview($variants, $rule, $channels, $this->onlyIncrease);
    }

    public function previewRule(): void
    {
        $this->showRulePreview = true;
    }

    public function applyRule(\App\Services\Pricing\PriceRuleApplier $applier): void
    {
        $rule = \App\Models\PriceRule::with('lines')->find($this->ruleId);
        if (! $rule) {
            return;
        }
        $count = $applier->apply($this->rulePreviewRows(), $rule, auth()->id());
        $this->showRulePreview = false;
        $this->fillForm();
        $this->notice = $count
            ? "Regelwerk „{$rule->name}“ angewendet, {$count} Preise geändert. Shopify-Preise sind noch nicht übertragen."
            : 'Es hat sich kein Preis geändert.';
        $this->noticeType = 'ok';
    }

    public function addVariant(\App\Services\Shopify\CatalogWriter $writer): void
    {
        $this->validate([
            'newVariant.sku' => ['required', 'string', 'max:255', 'unique:variants,sku'],
            'newVariant.barcode' => ['nullable', new Gtin],
            'newVariant.price' => ['required', fn ($a, $v, $f) => Format::parseDecimal($v) === null ? $f('Bitte einen Preis eingeben.') : null],
        ], ['newVariant.sku.unique' => 'Diese SKU gibt es schon.', 'newVariant.sku.required' => 'Bitte eine SKU angeben.']);
        $opts = array_map('trim', array_pad($this->newVariant['options'], count($this->product->options ?? []), ''));
        if (in_array('', $opts, true)) {
            $this->addError('newVariant', 'Bitte für jede Option einen Wert angeben.');

            return;
        }
        try {
            $writer->addVariant($this->product, [
                'options' => $opts, 'sku' => trim($this->newVariant['sku']), 'barcode' => trim($this->newVariant['barcode']) ?: null,
                'price' => Format::parseDecimal($this->newVariant['price']), 'compare_at_price' => null,
            ], auth()->id());
        } catch (\Throwable $e) {
            $this->addError('newVariant', $e->getMessage());

            return;
        }
        $this->reset('showAddVariant', 'newVariant');
        $this->fillForm();
        $this->notice = 'Variante in Shopify angelegt.';
        $this->noticeType = 'ok';
    }

    public function revert(ProductEditor $editor): void
    {
        $count = $editor->revertToShopify($this->product->fresh('variants'), auth()->id());
        $this->fillForm();
        $this->notice = $count ? 'Lokale Änderungen verworfen, es gilt wieder der Stand aus Shopify.' : 'Es gab keine lokalen Änderungen.';
        $this->noticeType = 'ok';
    }

    public function render()
    {
        $p = $this->product;
        $vat = (float) Setting::get('billbee.vat_rate', 19);
        $pending = $p->allPendingChanges();
        $costs = $p->variants->whereNotNull('cost_net');
        $margins = $p->variants->map(fn ($v) => $v->cost_net !== null && (float) $v->price > 0
            ? ($v->marginNet($vat) / ((float) $v->price / (1 + $vat / 100))) * 100 : null)->filter(fn ($m) => $m !== null);

        $channels = \App\Models\Channel::active()->get();
        $calc = new \App\Services\Pricing\PriceCalculator($vat);
        $priceVariants = $this->tab === 'preise' ? $p->variants()->with('channelPrices')->get() : collect();

        $sales = app(\App\Services\Sales\SalesStats::class)->period(30)['variants'];
        $sold30 = $p->variants->sum(fn ($v) => $sales[$v->id]['quantity'] ?? 0);

        return view('livewire.products.edit', [
            'sold30' => $sold30,
            'hasOrders' => \App\Models\Order::exists(),
            'channels' => $channels,
            'calc' => $calc,
            'priceVariants' => $priceVariants,
            'priceRules' => $this->tab === 'preise' ? \App\Models\PriceRule::orderBy('name')->get() : collect(),
            'rulePreview' => $this->tab === 'preise' && $this->showRulePreview ? $this->rulePreviewRows() : [],
            'pending' => $pending,
            'pendingCount' => count($pending['product']) + collect($pending['variants'])->sum(fn ($f) => count($f)) + ($p->attributesPending() ? 1 : 0),
            'attributesPending' => $p->attributesPending(),
            'images' => $p->images()->get(),
            'logs' => $this->tab === 'verlauf' ? $p->changeLogs()->with('user:id,name', 'variant:id,title,option1,option2,option3,sku')->limit(200)->get() : collect(),
            'vat' => $vat,
            'stock' => $p->variants->whereNotNull('stock')->sum('stock'),
            'hasStock' => $p->variants->whereNotNull('stock')->isNotEmpty(),
            'costMin' => $costs->min('cost_net'),
            'marginAvg' => $margins->isNotEmpty() ? $margins->avg() : null,
            'shopifyConfigured' => app(\App\Services\Shopify\ShopifyClient::class)->isConfigured(),
            'shopifyAdminUrl' => $p->shopify_id && Setting::get('shopify.shop')
                ? 'https://'.preg_replace('#^https?://#', '', rtrim(Setting::get('shopify.shop'), '/')).'/admin/products/'.basename($p->shopify_id) : null,
        ])->title($p->title);
    }
}
