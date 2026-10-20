@php use App\Support\Format; @endphp
<div>
    <div class="page-head">
        <h1>Berichte</h1>
        <span class="spacer"></span>
        <div class="seg" role="group" aria-label="Bericht">
            <button type="button" @class(['seg-btn', 'is-active' => $view === 'monat']) wire:click="$set('view', 'monat')">Monatsabschluss</button>
            <button type="button" @class(['seg-btn', 'is-active' => $view === 'woche']) wire:click="$set('view', 'woche')">Wochenrückblick</button>
        </div>
    </div>

    @if ($report)
        @php
            $t = $report['total'];
            $pct = fn ($a, $b) => $b > 0 ? (($a - $b) >= 0 ? '▲ ' : '▼ ').number_format(abs(($a - $b) / $b * 100), 0, ',', '.').' % zum Vormonat' : 'kein Vergleich';
        @endphp
        <div class="row" style="margin-bottom:14px">
            <select class="input" style="width:auto" wire:model.live="month" aria-label="Monat">
                @foreach ($months as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
            <a class="btn btn-primary" href="{{ route('reports.monthly-pdf', ['year' => $y, 'month' => $m]) }}">Als PDF herunterladen</a>
            <span class="muted small">Den Monatsabschluss kannst du dir unter <a href="{{ route('settings.account') }}" wire:navigate>Mein Konto</a> jeden Monat per Mail schicken lassen.</span>
        </div>
        <div class="kpis">
            <div class="kpi"><div class="label">Umsatz brutto</div><div class="value">{{ Format::money($t['gross'], 0) }}</div><div class="small muted">{{ $pct($t['gross'], $report['previous']['gross']) }}</div></div>
            <div class="kpi"><div class="label">Umsatz netto</div><div class="value">{{ Format::money($t['net'], 0) }}</div><div class="small muted">USt. {{ Format::money($t['vat'], 0) }}</div></div>
            <div class="kpi"><div class="label">Rohertrag</div><div class="value">{{ Format::money($t['profit'], 0) }}</div><div class="small muted">{{ $pct($t['profit'], $report['previous']['profit']) }}</div></div>
            <div class="kpi"><div class="label">Werbekosten</div><div class="value">{{ Format::money($t['ad_cost'], 0) }}</div><div class="small muted">ACoS {{ $report['ads']['acos'] === null ? '–' : Format::percent($report['ads']['acos']) }}</div></div>
            <div class="kpi"><div class="label">Nach Werbung</div><div class="value" @style(['color: var(--bad)' => $t['after_ads'] < 0])>{{ Format::money($t['after_ads'], 0) }}</div><div class="small muted">{{ $t['orders'] }} Bestellungen, Ø {{ Format::money($t['avg_order']) }}</div></div>
        </div>
        <div class="grid-2">
            <div class="card">
                <div class="card-head"><h2>Je Kanal</h2></div>
                <div class="table-wrap">
                <table class="list">
                    <thead><tr><th>Kanal</th><th class="num">Best.</th><th class="num">Brutto</th><th class="num">Netto</th><th class="num">Rohertrag</th></tr></thead>
                    <tbody>
                    @forelse ($report['channels'] as $c)
                        <tr><td>{{ $c['name'] }}</td><td class="num">{{ $c['orders'] }}</td><td class="num">{{ Format::money($c['gross'], 0) }}</td><td class="num">{{ Format::money($c['net'], 0) }}</td><td class="num">{{ Format::money($c['profit'], 0) }}</td></tr>
                    @empty
                        <tr><td colspan="5" class="empty">Keine Verkäufe in diesem Monat.</td></tr>
                    @endforelse
                    </tbody>
                </table>
                </div>
            </div>
            <div class="card">
                <div class="card-head"><h2>Bringt am meisten Rohertrag</h2></div>
                <div class="table-wrap">
                <table class="list">
                    <thead><tr><th>Artikel</th><th class="num">Stück</th><th class="num">Rohertrag</th></tr></thead>
                    <tbody>
                    @forelse ($report['top'] as $row)
                        <tr><td>{{ \Illuminate\Support\Str::limit($row['name'], 60) }}</td><td class="num">{{ number_format($row['quantity'], 0, ',', '.') }}</td><td class="num">{{ Format::money($row['profit'], 0) }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="empty">Keine zugeordneten Verkäufe.</td></tr>
                    @endforelse
                    </tbody>
                </table>
                </div>
            </div>
        </div>
        <p class="muted small">Rohertrag nach EK, Kanalgebühren und Versand, ohne Fixkosten. @if ($t['items_without_cost']){{ $t['items_without_cost'] }} Positionen ohne EK sind nicht enthalten. @endif Ersetzt keine Buchhaltung.</p>
    @endif

    @if ($week)
        <div class="card card-pad">
            <h2>Deine Woche: {{ $week['period'] }}</h2>
            <div class="kpis" style="margin-top:12px">
                <div class="kpi"><div class="label">Umsatz</div><div class="value">{{ $week['revenue'] }}</div><div class="small muted">{{ $week['revenue_change'] }}</div></div>
                <div class="kpi"><div class="label">Rohertrag</div><div class="value">{{ $week['profit'] }}</div><div class="small muted">{{ $week['profit_change'] }}</div></div>
                <div class="kpi"><div class="label">Bestellungen</div><div class="value">{{ $week['orders'] }}</div></div>
            </div>
            <div class="grid-2">
                <div>
                    <h3>Je Kanal</h3>
                    <ul>@foreach ($week['channels'] as $c)<li>{{ $c['name'] }}: {{ $c['revenue'] }} Umsatz, {{ $c['profit'] }} Rohertrag</li>@endforeach</ul>
                    <h3>Meistverkauft</h3>
                    <ul>@forelse ($week['top'] as $line)<li>{{ $line }}</li>@empty<li class="muted">nichts</li>@endforelse</ul>
                </div>
                <div>
                    <h3>Chancen für diese Woche</h3>
                    <ul>@forelse ($week['chances'] as $line)<li>{{ $line }}</li>@empty<li class="muted">keine</li>@endforelse</ul>
                    <h3>Offene Hinweise</h3>
                    <ul>@forelse ($week['alerts'] as $line)<li>{{ $line }}</li>@empty<li class="muted">keine</li>@endforelse</ul>
                </div>
            </div>
        </div>
    @endif
</div>
