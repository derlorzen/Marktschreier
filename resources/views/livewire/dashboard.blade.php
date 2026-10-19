@php
    use App\Support\Format;
    $change = function (float $now, float $before) {
        if ($before <= 0) return null;
        return ($now - $before) / $before * 100;
    };
    $periods = [7 => '7 Tage', 30 => '30 Tage', 90 => '90 Tage', 365 => '12 Monate'];
    $maxDay = max(1, collect($daily)->max('revenue'));
@endphp
<div>
    <div class="page-head">
        <h1>{{ $greeting }}, {{ auth()->user()->name }}</h1>
        <span class="spacer"></span>
        <div class="seg" role="group" aria-label="Zeitraum">
            @foreach ($periods as $d => $label)
                <button type="button" @class(['seg-btn', 'is-active' => $days === $d]) aria-pressed="{{ $days === $d ? 'true' : 'false' }}" wire:click="$set('days', {{ $d }})">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    @if ($pending)
        <div class="notice notice-warn row" style="margin-bottom:16px">
            <span><strong>{{ $pending }} {{ $pending === 1 ? 'Artikel hat' : 'Artikel haben' }}</strong> Änderungen, die noch nicht in Shopify sind.</span>
            <a class="btn btn-sm" style="margin-left:auto" href="{{ route('products.index', ['sync' => 'open']) }}" wire:navigate>Ansehen</a>
        </div>
    @endif

    @if (! $hasOrders)
        <div class="card empty">
            <h2 style="color:var(--ink)">Noch keine Verkäufe</h2>
            <p>Umsatz und Gewinn kommen aus den Bestellungen in Billbee. Unter <a href="{{ route('sync') }}" wire:navigate>Abgleich</a> „Verkäufe holen“ starten.</p>
        </div>
    @else
        <div class="kpis">
            @foreach ([
                ['Umsatz brutto', Format::money($now['revenue_gross'], 0), $change($now['revenue_gross'], $before['revenue_gross'])],
                ['Gewinn', Format::money($now['profit'], 0), $change($now['profit'], $before['profit'])],
                ['Bestellungen', number_format($now['orders'], 0, ',', '.'), $change($now['orders'], $before['orders'])],
                ['Ø Bestellwert', Format::money($now['orders'] ? $now['revenue_gross'] / $now['orders'] : null), $change($now['orders'] ? $now['revenue_gross'] / $now['orders'] : 0, $before['orders'] ? $before['revenue_gross'] / $before['orders'] : 0)],
            ] as [$label, $value, $delta])
                <div class="kpi">
                    <div class="label">{{ $label }}</div>
                    <div class="value">{{ $value }}</div>
                    <div class="small" style="color: {{ $delta === null ? 'var(--muted)' : ($delta >= 0 ? 'var(--accent-strong)' : 'var(--warn-fg)') }}">
                        @if ($delta === null) kein Vergleich @else {{ $delta >= 0 ? '▲' : '▼' }} {{ number_format(abs($delta), 0, ',', '.') }} % zum Zeitraum davor @endif
                    </div>
                </div>
            @endforeach
        </div>
        @if ($now['items_without_cost'])
            <p class="muted small" style="margin:-6px 0 14px">{{ $now['items_without_cost'] }} verkaufte Positionen ohne EK sind im Gewinn nicht enthalten.</p>
        @endif

        @if ($chances->isNotEmpty())
            <div class="card" style="margin-bottom:16px">
                <div class="card-head"><h2>Die wichtigsten Chancen</h2><a class="small" style="margin-left:auto" href="{{ route('opportunities') }}" wire:navigate>Alle ansehen</a></div>
                @foreach ($chances as $o)
                    <a class="chance-row" href="{{ $o->actionUrl ?? route('opportunities') }}" wire:navigate>
                        <span class="badge {{ $o->kind === 'ads' ? 'badge-ok' : '' }}">{{ \App\Services\Opportunities\Opportunity::typeLabel($o->type) }}</span>
                        <span class="chance-title">{{ $o->title }}</span>
                        @if ($o->monthlyValue)<strong class="nowrap" style="color:var(--accent-strong)">bis {{ Format::money($o->monthlyValue, 0) }} im Monat</strong>@endif
                    </a>
                @endforeach
            </div>
        @endif

        <div class="card card-pad" style="margin-bottom:16px">
            <h2 style="margin-bottom:12px">Umsatz pro Tag</h2>
            <div class="daychart" role="img" aria-label="Umsatz pro Tag, höchster Tag {{ Format::money($maxDay, 0) }}">
                @foreach ($daily as $d)
                    @php $h = $d['revenue'] / $maxDay * 100; @endphp
                    <div class="daybar" tabindex="0">
                        <span class="fill" style="height: {{ max($h, $d['revenue'] > 0 ? 2 : 0) }}%"></span>
                        <span class="tip">{{ \Illuminate\Support\Carbon::parse($d['date'])->format('d.m.') }}<br><strong>{{ Format::money($d['revenue'], 0) }}</strong></span>
                    </div>
                @endforeach
            </div>
            <div class="row small muted" style="justify-content:space-between;margin-top:6px">
                <span>{{ \Illuminate\Support\Carbon::parse($daily[0]['date'])->format('d.m.') }}</span>
                <span>höchster Tag {{ Format::money($maxDay, 0) }}</span>
                <span>heute</span>
            </div>
        </div>

        <div class="grid-2">
            <div class="card">
                <div class="card-head"><h2>Je Kanal</h2></div>
                <div class="table-wrap">
                <table class="list">
                    <thead><tr><th>Kanal</th><th>Umsatz</th><th class="num">Gewinn</th><th class="num">Best.</th></tr></thead>
                    <tbody>
                    @foreach ($channelRows as $row)
                        @php $d = $change($row['revenue_gross'], $row['prev']); @endphp
                        <tr>
                            <td class="nowrap">{{ $row['name'] }}</td>
                            <td style="min-width:160px">
                                <div class="hbar"><span style="width: {{ $row['revenue_gross'] / $maxChannel * 100 }}%"></span></div>
                                <span class="small">{{ Format::money($row['revenue_gross'], 0) }}</span>
                                @if ($d !== null)<span class="small muted"> {{ $d >= 0 ? '+' : '−' }}{{ number_format(abs($d), 0, ',', '.') }} %</span>@endif
                            </td>
                            <td class="num" @style(['color: var(--bad)' => $row['profit'] < 0])>{{ Format::money($row['profit'], 0) }}</td>
                            <td class="num">{{ number_format($row['orders'], 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            </div>
            <div class="card">
                <div class="card-head"><h2>Bringt am meisten Gewinn</h2><span class="muted small">je Variante</span></div>
                <div class="table-wrap">
                <table class="list">
                    <thead><tr><th>Artikel</th><th class="num">Stück</th><th class="num">Gewinn</th></tr></thead>
                    <tbody>
                    @forelse ($top as $row)
                        <tr>
                            <td><a class="title-link" href="{{ route('products.edit', $row['variant']->product_id) }}" wire:navigate>{{ \Illuminate\Support\Str::limit($row['variant']->product->title, 48) }}</a>
                                @if ($row['variant']->label() !== 'Standard')<div class="small muted">{{ $row['variant']->label() }}</div>@endif</td>
                            <td class="num">{{ number_format($row['quantity'], 0, ',', '.') }}</td>
                            <td class="num">{{ Format::money($row['profit'], 0) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="empty">Keine zugeordneten Verkäufe im Zeitraum.</td></tr>
                    @endforelse
                    </tbody>
                </table>
                </div>
            </div>
        </div>
        <p class="muted small">Gewinn nach EK, Kanalgebühren und Versand laut <a href="{{ route('pricing') }}" wire:navigate>Kanäle und Preisregeln</a>, ohne Werbung und Fixkosten. Stand der Verkäufe: {{ $lastOrders?->finished_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') ?? 'unbekannt' }}.</p>
    @endif
</div>
