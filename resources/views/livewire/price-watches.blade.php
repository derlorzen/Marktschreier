@php use App\Support\Format; use App\Models\PriceWatch; @endphp
<div>
    <div class="page-head">
        <h1>Preiswächter</h1>
        <span class="muted small">Buy Box auf Amazon</span>
        <span class="spacer"></span>
        @if ($connected)<button class="btn" wire:click="checkNow">Jetzt prüfen <span class="spin" wire:loading wire:target="checkNow"></span></button>@endif
    </div>

    <div class="notice" style="margin-bottom:14px">
        Für Artikel, die auch andere Händler anbieten. Der Wächter vergleicht alle 30 Minuten die Angebote auf Amazon und schlägt einen Preis vor, mit dem du die Buy Box holst.
        Er geht nie unter deinen Mindestpreis und nie über den Höchstpreis. Mit „automatisch“ setzt er den Preis selbst, höchstens um die eingestellte Änderung pro Tag.
    </div>

    @if (! $connected)
        <div class="notice notice-warn">Amazon ist nicht verbunden. @can('admin')<a href="{{ route('settings.amazon') }}" wire:navigate>Jetzt verbinden</a>@endcan</div>
    @endif
    @if ($notice)<div class="notice notice-ok row" role="status" style="margin-bottom:14px"><span>{{ $notice }}</span><button class="linkbtn" style="margin-left:auto" wire:click="$set('notice', null)">Ausblenden</button></div>@endif
    @if ($error)<div class="notice notice-bad" style="margin-bottom:14px">{{ $error }}</div>@endif

    @if ($editId !== null && $editVariant)
        <form class="card" wire:submit="save" style="margin-bottom:16px">
            <div class="card-head"><h2>{{ $editId ? 'Wächter bearbeiten' : 'Neuer Wächter' }}: {{ $editVariant->product->title }}@if ($editVariant->label() !== 'Standard') ({{ $editVariant->label() }})@endif</h2></div>
            <div class="card-pad grid-3">
                <label class="field span-2">Vorgehen
                    <select class="input" wire:model="form.strategy">
                        @foreach (PriceWatch::STRATEGIES as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                    </select>
                </label>
                <label class="field">Änderung pro Tag höchstens (%)
                    <input class="input num" type="number" min="1" max="100" wire:model="form.max_daily_change">
                    @error('form.max_daily_change') <span class="error">{{ $message }}</span> @enderror
                </label>
                <label class="field">Mindestpreis € <span class="hint">vorgeschlagen: kein Verlust nach EK und Gebühren</span>
                    <input class="input num" wire:model="form.min_price" inputmode="decimal">
                    @error('form.min_price') <span class="error">{{ $message }}</span> @enderror
                </label>
                <label class="field">Höchstpreis € <span class="hint">leer = keine Grenze</span>
                    <input class="input num" wire:model="form.max_price" inputmode="decimal">
                    @error('form.max_price') <span class="error">{{ $message }}</span> @enderror
                </label>
                <label class="check" style="align-self:end"><input type="checkbox" wire:model="form.auto"> Automatisch anpassen</label>
                <div class="row" style="grid-column:1/-1">
                    <button class="btn btn-primary" type="submit">Speichern</button>
                    <button class="btn" type="button" wire:click="$set('editId', null)">Abbrechen</button>
                </div>
            </div>
        </form>
    @endif

    <div class="card">
        <div class="filters">
            <div class="search">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input class="input" type="search" wire:model.live.debounce.300ms="search" placeholder="Artikel mit Amazon-Angebot hinzufügen: Titel, Marke oder SKU" aria-label="Artikel suchen">
            </div>
        </div>
        @if ($candidates->isNotEmpty())
            <div style="border-bottom:1px solid var(--line)">
                @foreach ($candidates as $c)
                    <div class="chance-row" wire:key="cand{{ $c->id }}">
                        <span class="chance-title">{{ $c->product->title }}@if ($c->label() !== 'Standard') ({{ $c->label() }})@endif <span class="muted small">{{ $c->product->vendor }} · {{ $c->sku }}</span></span>
                        <button class="btn btn-sm" wire:click="startNew({{ $c->id }})">Überwachen</button>
                    </div>
                @endforeach
            </div>
        @elseif (trim($search) !== '')
            <p class="muted small" style="padding:0 18px">Kein passender Artikel mit Amazon-Angebot gefunden.</p>
        @endif
        <div class="table-wrap">
            <table class="list">
                <thead><tr><th>Artikel</th><th>Buy Box</th><th class="num">Dein Preis</th><th class="num">Buy-Box-Preis</th><th class="num">Günstigster anderer</th><th class="num">Vorschlag</th><th>Grenzen</th><th></th></tr></thead>
                <tbody>
                @forelse ($watches as $w)
                    @php $r = $w->last_result ?? []; @endphp
                    <tr wire:key="w{{ $w->id }}" @if (! $w->active) style="opacity:.55" @endif>
                        <td style="min-width:220px"><a class="title-link" href="{{ route('products.edit', $w->variant->product_id) }}?tab=marktplaetze" wire:navigate>{{ \Illuminate\Support\Str::limit($w->variant->product->title, 45) }}</a>
                            <div class="small muted">{{ $w->variant->label() !== 'Standard' ? $w->variant->label().' · ' : '' }}{{ PriceWatch::STRATEGIES[$w->strategy] }}{{ $w->auto ? ' · automatisch' : '' }}</div></td>
                        <td>
                            @if (! $w->checked_at)<span class="badge">noch nicht geprüft</span>
                            @elseif ($r['won'] ?? false)<span class="badge badge-ok">bei dir</span>
                            @else<span class="badge badge-warn">anderer Händler</span>@endif
                            @if ($r['offers'] ?? null)<div class="small muted">{{ $r['offers'] }} Angebote</div>@endif
                        </td>
                        <td class="num">{{ Format::money($amazon ? $w->variant->priceOn($amazon) : null) }}</td>
                        <td class="num">{{ Format::money($r['buybox'] ?? null) }}</td>
                        <td class="num">{{ Format::money($r['lowest'] ?? null) }}</td>
                        <td class="num">
                            @if ($w->suggested_price)<strong>{{ Format::money($w->suggested_price) }}</strong>@else – @endif
                            @if ($r['note'] ?? null)<div class="small muted" style="max-width:200px;white-space:normal">{{ $r['note'] }}</div>@endif
                        </td>
                        <td class="small nowrap">{{ Format::money($w->min_price) }} bis {{ $w->max_price ? Format::money($w->max_price) : 'offen' }}</td>
                        <td class="nowrap" style="text-align:right">
                            @if ($w->suggested_price)<button class="btn btn-sm btn-primary" wire:click="apply({{ $w->id }})">Übernehmen</button>@endif
                            <button class="btn btn-sm" wire:click="edit({{ $w->id }})">Bearbeiten</button>
                            <button class="btn btn-sm" wire:click="toggle({{ $w->id }})">{{ $w->active ? 'Pausieren' : 'Fortsetzen' }}</button>
                            <button class="linkbtn small" wire:click="remove({{ $w->id }})" wire:confirm="Wächter entfernen?">Entfernen</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="empty">Noch keine Artikel überwacht. Oben einen Artikel suchen und „Überwachen“ wählen.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
