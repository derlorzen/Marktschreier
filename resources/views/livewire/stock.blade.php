<div>
    <div class="page-head">
        <h1>Bestand</h1>
        <span class="muted small">Billbee ist führend, hier wird nur angezeigt und gewarnt.</span>
    </div>
    @if ($warnCount)
        <div class="notice notice-bad" style="margin-bottom:14px"><strong>{{ $warnCount }} {{ $warnCount === 1 ? 'Variante' : 'Varianten' }} mit Überverkaufsgefahr:</strong> Ein Marktplatz zeigt mehr Stück an, als im Lager liegen. Bestandsabgleich in Billbee prüfen.</div>
    @endif
    <div class="card">
        <div class="filters">
            <div class="search">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input class="input" type="search" wire:model.live.debounce.300ms="search" placeholder="Artikel oder SKU" aria-label="Suche">
            </div>
            <button @class(['btn btn-sm', 'btn-primary' => $filter === '']) wire:click="$set('filter', '')">Alle</button>
            <button @class(['btn btn-sm', 'btn-primary' => $filter === 'warn']) wire:click="$set('filter', 'warn')">Überverkaufsgefahr · {{ $warnCount }}</button>
            <button @class(['btn btn-sm', 'btn-primary' => $filter === 'empty']) wire:click="$set('filter', 'empty')">Ausverkauft · {{ $emptyCount }}</button>
            <button @class(['btn btn-sm', 'btn-primary' => $filter === 'fba']) wire:click="$set('filter', 'fba')">Bei FBA · {{ $fbaCount }}</button>
        </div>
        <div class="table-wrap">
            <table class="list">
                <thead><tr>
                    <th>Artikel</th><th class="num">Eigenes Lager</th><th>Je Lager</th><th class="num">FBA verfügbar</th><th class="num">FBA unterwegs</th>
                    @if ($hasEbay)<th class="num">eBay zeigt</th>@endif
                    @if ($hasAmazon)<th class="num">Amazon zeigt</th>@endif
                    <th>Hinweis</th>
                </tr></thead>
                <tbody>
                @forelse ($rows as $r)
                    @php $v = $r['variant']; @endphp
                    <tr wire:key="s{{ $v->id }}" @if ($r['warnings']) style="background:var(--bad-tint)" @endif>
                        <td style="min-width:240px"><a class="title-link" href="{{ route('products.edit', $v->product_id) }}" wire:navigate>{{ \Illuminate\Support\Str::limit($v->product->title, 50) }}</a>
                            <div class="small muted">{{ $v->label() !== 'Standard' ? $v->label().' · ' : '' }}<span class="mono">{{ $v->sku }}</span></div></td>
                        <td class="num"><strong>{{ $r['own'] ?? '–' }}</strong></td>
                        <td class="small">@foreach ($r['locations'] as $loc){{ $loc['name'] }}: {{ $loc['quantity'] }}@if ($loc['reserved']) <span class="muted">({{ $loc['reserved'] }} reserviert)</span>@endif<br>@endforeach</td>
                        <td class="num">{{ $r['fba']['fulfillable'] ?? '–' }}</td>
                        <td class="num">{{ $r['fba']['inbound'] ?? '–' }}</td>
                        @if ($hasEbay)<td class="num">{{ $r['shown']['ebay'] ?? '–' }}</td>@endif
                        @if ($hasAmazon)<td class="num">{{ $r['shown']['amazon'] ?? '–' }}</td>@endif
                        <td class="small" style="color:var(--bad)">{{ implode('. ', $r['warnings']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="empty">Keine Varianten in dieser Auswahl.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <p class="muted small">Eigenes Lager und Lagerorte kommen aus Billbee, FBA aus Amazon, die angezeigten Mengen beim Lesen der Angebote (Stand siehe Abgleich).</p>
</div>
