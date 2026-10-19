@php
    $labels = ['products_new' => 'neu', 'products_updated' => 'aktualisiert', 'variants' => 'Varianten', 'conflicts' => 'Konflikte',
        'billbee_products' => 'Billbee-Artikel', 'matched' => 'zugeordnet', 'by_sku' => 'per SKU', 'by_ean' => 'per GTIN', 'unmatched' => 'ohne Treffer', 'ambiguous' => 'SKU doppelt', 'fields' => 'Felder',
        'orders' => 'Bestellungen', 'items' => 'Positionen', 'items_unmatched' => 'Positionen ohne Artikel', 'orders_without_channel' => 'ohne Kanal'];
    $fmt = fn ($d) => $d?->timezone('Europe/Berlin')->format('d.m.Y H:i');
@endphp
<div @if ($active) wire:poll.3s @endif>
    <div class="page-head"><h1>Abgleich</h1></div>

    @if ($notice)
        <div class="notice notice-ok row" role="status" style="margin-bottom:14px"><span>{{ $notice }}</span>
            <button class="linkbtn" style="margin-left:auto" wire:click="$set('notice', null)">Ausblenden</button></div>
    @endif

    <div class="grid-3" style="margin-bottom:16px">
        <div class="card card-pad stack">
            <h2>Shopify</h2>
            <p class="muted small" style="margin:0">Artikel, Texte, Varianten und Preise. Marktschreier ist nach dem Import führend: hier geänderte Felder werden erst nach deiner Freigabe übertragen.</p>
            @if ($shopifyReady)
                <div class="small">Letzter Import: {{ $lastImport ? $fmt($lastImport->finished_at) : 'noch keiner' }}</div>
                <div class="row">
                    <button class="btn btn-primary" wire:click="importShopify(true)" wire:loading.attr="disabled">{{ $lastImport ? 'Alles neu einlesen' : 'Ersten Import starten' }}</button>
                    @if ($lastImport)<button class="btn" wire:click="importShopify(false)" wire:loading.attr="disabled">Änderungen holen</button>@endif
                </div>
            @else
                <div class="notice notice-warn small">Noch nicht eingerichtet. <a href="{{ route('settings') }}" wire:navigate>Zu den Einstellungen</a></div>
            @endif
        </div>
        <div class="card card-pad stack">
            <h2>Billbee</h2>
            <p class="muted small" style="margin:0">Liest Einkaufspreis, Bestand und Verkäufe (ohne Kundendaten), Zuordnung über SKU, sonst GTIN. Billbee bleibt führend für Bestand und Aufträge, Marktschreier schreibt dort nichts.</p>
            @if ($billbeeReady)
                <div class="small">EK und Bestand: {{ $lastBillbee ? $fmt($lastBillbee->finished_at) : 'noch nicht abgeglichen' }}</div>
                <div class="small">Verkäufe: {{ $lastOrders ? $fmt($lastOrders->finished_at).', '.number_format($orderCount, 0, ',', '.').' Bestellungen' : 'noch nicht eingelesen' }}</div>
                <div class="row">
                    <button class="btn btn-primary" wire:click="syncBillbee" wire:loading.attr="disabled">EK und Bestand holen</button>
                    <button class="btn" wire:click="syncOrders" wire:loading.attr="disabled">Verkäufe holen</button>
                </div>
            @else
                <div class="notice notice-warn small">Noch nicht eingerichtet. <a href="{{ route('settings') }}" wire:navigate>Zu den Einstellungen</a></div>
            @endif
        </div>
        <div class="card card-pad stack">
            <h2>Offene Änderungen</h2>
            <div class="row small">
                <span class="badge badge-warn">{{ $counts['pending'] ?? 0 }} nicht übertragen</span>
                <span class="badge badge-amber">{{ $counts['conflict'] ?? 0 }} Konflikte</span>
                <span class="badge badge-bad">{{ $counts['error'] ?? 0 }} Fehler</span>
            </div>
            <div class="row">
                <button class="btn btn-primary" wire:click="pushAll" wire:confirm="Alle Artikel mit offenen Änderungen (ohne Konflikte und Fehler) jetzt zu Shopify übertragen?" @disabled(! ($counts['pending'] ?? 0))>Alle übertragen</button>
                <a class="btn" href="{{ route('products.index', ['sync' => 'open']) }}" wire:navigate>Ansehen</a>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Protokoll</h2>@if ($active)<span class="spin" style="color:var(--accent)" aria-label="läuft"></span>@endif</div>
        @if ($runs->isEmpty())
            <div class="empty">Noch keine Läufe.</div>
        @else
            <div class="table-wrap">
            <table class="list">
                <thead><tr><th>Start</th><th>Art</th><th>Status</th><th>Ergebnis</th><th>Hinweis</th></tr></thead>
                <tbody>
                @foreach ($runs as $run)
                    <tr wire:key="r{{ $run->id }}">
                        <td class="nowrap small">{{ $fmt($run->started_at ?? $run->created_at) }}</td>
                        <td class="nowrap">{{ $run->typeLabel() }}</td>
                        <td>
                            @switch($run->status)
                                @case('ok') <span class="badge badge-ok">fertig</span> @break
                                @case('failed') <span class="badge badge-bad">fehlgeschlagen</span> @break
                                @case('running') <span class="badge badge-amber">läuft</span> @break
                                @default <span class="badge">wartet</span>
                            @endswitch
                        </td>
                        <td class="small">
                            @foreach (($run->stats ?? []) as $k => $v)
                                @continue(is_array($v) || $k === 'pages' || $k === 'product_id' || ! isset($labels[$k]))
                                <span class="nowrap">{{ $v }} {{ $labels[$k] }}</span>@if (! $loop->last) · @endif
                            @endforeach
                            @if (! empty($run->stats['unmatched_examples']))
                                <details><summary class="muted">Ohne Treffer in Marktschreier</summary><span class="mono">{{ implode(', ', $run->stats['unmatched_examples']) }}</span></details>
                            @endif
                        </td>
                        <td class="small" style="max-width:380px;overflow-wrap:anywhere">{{ $run->message }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>
</div>
