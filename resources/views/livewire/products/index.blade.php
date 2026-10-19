@php use App\Support\Format; @endphp
<div>
    <x-article-tabs />

    <div class="page-head">
        <h1>Artikel</h1>
        <span class="muted">{{ number_format($products->total(), 0, ',', '.') }}@if ($filtered) von {{ number_format($total, 0, ',', '.') }}@endif</span>
        <span class="spacer"></span>
        <a class="btn btn-primary" href="{{ route('products.create') }}" wire:navigate>Neuer Artikel</a>
    </div>

    @if ($notice)
        <div class="notice notice-ok row" style="margin-bottom:14px">
            <span>{{ $notice }}</span>
            <button class="linkbtn" wire:click="$set('notice', null)" style="margin-left:auto">Ausblenden</button>
        </div>
    @endif

    @if ($total === 0)
        <div class="card empty">
            <h2 style="color:var(--ink)">Noch keine Artikel</h2>
            <p>Hinterlege unter <a href="{{ route('settings.shopify') }}" wire:navigate>Einstellungen</a> den Shopify-Zugang und starte dann unter <a href="{{ route('sync') }}" wire:navigate>Abgleich</a> den ersten Import.</p>
        </div>
    @else
    <div class="card">
        <div class="filters">
            <div class="search">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <label class="sr-only" for="q">Suche</label>
                <input id="q" class="input" type="search" wire:model.live.debounce.300ms="search" placeholder="Titel, Marke, SKU oder GTIN" autocomplete="off">
            </div>
            <select class="input" wire:model.live="vendor" aria-label="Marke">
                <option value="">Alle Marken</option>
                @foreach ($vendors as $v)<option value="{{ $v }}">{{ $v }}</option>@endforeach
            </select>
            <select class="input" wire:model.live="type" aria-label="Produktart">
                <option value="">Alle Produktarten</option>
                @foreach ($types as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach
            </select>
            <select class="input" wire:model.live="status" aria-label="Status in Shopify">
                <option value="">Jeder Status</option>
                <option value="ACTIVE">Aktiv</option>
                <option value="DRAFT">Entwurf</option>
                <option value="ARCHIVED">Archiviert</option>
            </select>
            <select class="input" wire:model.live="sync" aria-label="Abgleich">
                <option value="">Jeder Abgleich</option>
                <option value="open">Offen (nicht übertragen, Fehler, Konflikt)</option>
                <option value="pending">Nicht übertragen</option>
                <option value="error">Fehler</option>
                <option value="conflict">Konflikt</option>
                <option value="synced">Abgeglichen</option>
            </select>
            <select class="input" wire:model.live="missing" aria-label="Fehlende Daten">
                <option value="">Alle Daten</option>
                <option value="gtin">GTIN fehlt</option>
                <option value="sku">SKU fehlt</option>
                <option value="cost">EK fehlt</option>
                <option value="billbee">Nicht in Billbee gefunden</option>
            </select>
            @if ($filtered)
                <button class="btn btn-sm" wire:click="resetFilters">Filter zurücksetzen</button>
            @endif
        </div>

        @if ($selectedCount > 0)
            <div class="bulkbar">
                <strong>{{ $selectedCount }} ausgewählt</strong>
                @if (! $selectAllMatching && count($selected) === $products->count() && $products->total() > $products->count())
                    <button class="linkbtn" wire:click="$set('selectAllMatching', true)">Alle {{ $products->total() }} Treffer auswählen</button>
                @endif
                <select class="input input-sm" style="width:auto" wire:model.live="bulkAction" aria-label="Gruppenfunktion">
                    <option value="">Gruppenfunktion wählen</option>
                    <option value="push">Zu Shopify übertragen</option>
                    <option value="price_rule">Preise mit Regelwerk neu rechnen</option>
                    <option value="tag_add">Tag hinzufügen</option>
                    <option value="tag_remove">Tag entfernen</option>
                    <option value="vendor">Marke setzen</option>
                    <option value="type">Produktart setzen</option>
                    <option value="status">Status setzen</option>
                </select>
                @if (in_array($bulkAction, ['tag_add', 'tag_remove', 'vendor', 'type']))
                    <input class="input input-sm" style="width:200px" wire:model="bulkValue" placeholder="Wert" aria-label="Wert">
                @elseif ($bulkAction === 'status')
                    <select class="input input-sm" style="width:auto" wire:model="bulkValue" aria-label="Status">
                        <option value="">Status wählen</option>
                        <option value="ACTIVE">Aktiv</option>
                        <option value="DRAFT">Entwurf</option>
                        <option value="ARCHIVED">Archiviert</option>
                    </select>
                @endif
                @if ($bulkAction === 'price_rule')
                    <select class="input input-sm" style="width:auto" wire:model.live="bulkRuleId" aria-label="Regelwerk">
                        @foreach ($priceRules as $rule)<option value="{{ $rule->id }}">{{ $rule->name }}</option>@endforeach
                    </select>
                    @foreach ($channels as $ch)
                        <label class="check"><input type="checkbox" value="{{ $ch->id }}" wire:model.live="bulkRuleChannels" wire:change="$set('bulkPreview', null)"> {{ $ch->name }}</label>
                    @endforeach
                    <label class="check"><input type="checkbox" wire:model.live="bulkOnlyIncrease" wire:change="$set('bulkPreview', null)"> Nur erhöhen</label>
                    @if (! $bulkPreview)
                        <button class="btn btn-sm btn-primary" wire:click="previewBulkRule">Vorschau <span class="spin" wire:loading wire:target="previewBulkRule"></span></button>
                    @endif
                @elseif ($bulkAction)
                    <button class="btn btn-sm btn-primary" wire:click="runBulk" wire:loading.attr="disabled">Ausführen <span class="spin" wire:loading wire:target="runBulk"></span></button>
                @endif
                @error('bulkValue') <span class="error">{{ $message }}</span> @enderror
                <button class="linkbtn" style="margin-left:auto" wire:click="clearSelection">Auswahl aufheben</button>
            </div>
            @if ($bulkAction === 'price_rule' && $bulkPreview)
                <div class="bulkbar" style="background:var(--warn-tint);align-items:flex-start;flex-direction:column;gap:6px">
                    @if ($bulkPreview['changed'])
                        <strong>{{ $bulkPreview['changed'] }} Preise ändern sich: {{ $bulkPreview['up'] }} steigen, {{ $bulkPreview['down'] }} sinken.</strong>
                    @else
                        <strong>Es würde sich kein Preis ändern.</strong>
                    @endif
                    @if ($bulkPreview['worst'])
                        <span>Größte Senkung: {{ number_format(abs($bulkPreview['worst']['d']) * 100, 0, ',', '.') }} % bei {{ $bulkPreview['worst']['name'] }} ({{ $bulkPreview['worst']['channel'] }}).</span>
                    @endif
                    @foreach ($bulkPreview['reasons'] as $reason => $n)
                        <span class="muted small">{{ $n }} übersprungen: {{ $reason }}</span>
                    @endforeach
                    <span class="muted small">Einmalig: Es bleibt keine Regel an den Artikeln hängen. Shopify-Preise werden als offene Änderung vorgemerkt.</span>
                    <div class="row">
                        <button class="btn btn-sm btn-primary" wire:click="runBulk" @disabled(! $bulkPreview['changed'])>Übernehmen <span class="spin" wire:loading wire:target="runBulk"></span></button>
                        <button class="btn btn-sm" wire:click="$set('bulkPreview', null)">Abbrechen</button>
                    </div>
                </div>
            @endif
        @endif

        <div class="table-wrap">
            <table class="list">
                <thead>
                    <tr>
                        @php $pageIds = $products->pluck('id')->all(); @endphp
                        <th style="width:36px"><input type="checkbox" class="check" aria-label="Alle auf dieser Seite auswählen"
                            @checked($pageIds && ! array_diff($pageIds, $selected)) wire:click="togglePage(@js($pageIds))"></th>
                        <th style="width:52px"><span class="sr-only">Bild</span></th>
                        @foreach (['title' => 'Artikel', 'vendor' => 'Marke'] as $key => $label)
                            <th><button wire:click="sortBy('{{ $key }}')">{{ $label }} @if ($sort === $key){{ $dir === 'asc' ? '↑' : '↓' }}@endif</button></th>
                        @endforeach
                        <th>SKU / GTIN</th>
                        <th class="num"><button wire:click="sortBy('price')">Preis @if ($sort === 'price'){{ $dir === 'asc' ? '↑' : '↓' }}@endif</button></th>
                        <th class="num">EK netto</th>
                        <th class="num"><button wire:click="sortBy('stock')">Bestand @if ($sort === 'stock'){{ $dir === 'asc' ? '↑' : '↓' }}@endif</button></th>
                        <th>Status</th>
                        <th><button wire:click="sortBy('updated')">Abgleich @if ($sort === 'updated'){{ $dir === 'asc' ? '↑' : '↓' }}@endif</button></th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($products as $product)
                    @php
                        $first = $product->variants->first();
                        $minCost = $product->variants->whereNotNull('cost_net')->min('cost_net');
                        $noGtin = $product->variants->contains(fn ($v) => blank($v->barcode));
                        [$syncLabel, $syncClass] = Format::pushStatusBadge($product->push_status);
                    @endphp
                    <tr wire:key="p{{ $product->id }}" @class(['is-selected' => $selectAllMatching || in_array($product->id, $selected)])>
                        <td><input type="checkbox" class="check" value="{{ $product->id }}" wire:model.live="selected" aria-label="{{ $product->title }} auswählen" @if ($selectAllMatching) checked disabled @endif></td>
                        <td>
                            @if ($img = $product->images->first())
                                <img class="thumb" src="{{ $img->sized(80) }}" alt="" loading="lazy">
                            @else
                                <span class="thumb" aria-hidden="true"></span>
                            @endif
                        </td>
                        <td style="min-width:260px">
                            <a class="title-link" href="{{ route('products.edit', $product) }}" wire:navigate>{{ $product->title }}</a>
                            <div class="muted small">{{ $product->product_type ?: 'Ohne Produktart' }} · {{ $product->variants_count }} {{ $product->variants_count === 1 ? 'Variante' : 'Varianten' }}</div>
                        </td>
                        <td>{{ $product->vendor }}</td>
                        <td class="small">
                            <span class="mono">{{ $first?->sku ?: '–' }}</span>@if ($product->variants_count > 1) <span class="muted">+{{ $product->variants_count - 1 }}</span>@endif
                            <div class="mono muted">{{ $first?->barcode ?: '' }}@if ($noGtin) <span class="badge badge-warn">GTIN fehlt</span>@endif</div>
                        </td>
                        <td class="num">
                            {{ Format::money($product->variants_min_price) }}
                            @if ((float) $product->variants_max_price !== (float) $product->variants_min_price)<div class="muted small">bis {{ Format::money($product->variants_max_price) }}</div>@endif
                        </td>
                        <td class="num">{{ Format::money($minCost) }}</td>
                        <td class="num">{{ $product->variants_sum_stock === null ? '–' : number_format((int) $product->variants_sum_stock, 0, ',', '.') }}</td>
                        <td><span @class(['badge', 'badge-ok' => $product->status === 'ACTIVE'])>{{ Format::shopifyStatus($product->status) }}</span></td>
                        <td><span class="badge {{ $syncClass }}" @if ($product->push_error) title="{{ $product->push_error }}" @endif>{{ $syncLabel }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="empty">Keine Artikel gefunden. @if ($filtered)<button class="linkbtn" wire:click="resetFilters">Filter zurücksetzen</button>@endif</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($products->hasPages())
            <div class="pager">
                <span class="muted small">{{ $products->firstItem() }} bis {{ $products->lastItem() }} von {{ $products->total() }}</span>
                <nav>
                    <button class="btn btn-sm" wire:click="previousPage" @disabled($products->onFirstPage())>Zurück</button>
                    <span class="muted small" style="align-self:center">Seite {{ $products->currentPage() }} von {{ $products->lastPage() }}</span>
                    <button class="btn btn-sm" wire:click="nextPage" @disabled(! $products->hasMorePages())>Weiter</button>
                </nav>
            </div>
        @endif
    </div>
    @endif
</div>
