@php use App\Support\Format; @endphp
<div>
    <div class="page-head">
        <h1>Werbung</h1>
        <span class="muted small">Amazon Sponsored Products @if ($lastSync) · Stand {{ $lastSync->timezone('Europe/Berlin')->format('d.m. H:i') }}@endif</span>
        <span class="spacer"></span>
        @if ($connected)<button class="btn" wire:click="syncNow" wire:loading.attr="disabled">Jetzt aktualisieren <span class="spin" wire:loading wire:target="syncNow"></span></button>@endif
    </div>

    @if (! $connected)
        <div class="card empty">
            <h2 style="color:var(--ink)">Amazon Werbung ist nicht verbunden</h2>
            <p>Nach dem Verbinden siehst du hier Kampagnen, Suchbegriffe und konkrete Vorschläge. @can('admin')<a href="{{ route('settings.ads') }}" wire:navigate>Jetzt verbinden</a>@endcan</p>
        </div>
    @else
    @if ($notice)<div class="notice notice-ok row" role="status" style="margin-bottom:14px"><span>{{ $notice }}</span><button class="linkbtn" style="margin-left:auto" wire:click="$set('notice', null)">Ausblenden</button></div>@endif
    @if ($error)<div class="notice notice-bad" style="margin-bottom:14px">{{ $error }}</div>@endif

    @if ($newProduct)
        <form class="card" wire:submit="createCampaign" style="margin-bottom:16px">
            <div class="card-head"><h2>Neue automatische Kampagne: {{ $newProduct->title }}</h2></div>
            <div class="card-pad grid-3">
                <label class="field">Tagesbudget € <input class="input num" wire:model="newBudget" inputmode="decimal"></label>
                <label class="field">Standardgebot je Klick € <input class="input num" wire:model="newBid" inputmode="decimal"></label>
                <p class="small muted" style="margin:0;align-self:end">Amazon sucht passende Suchbegriffe selbst. Nach ein bis zwei Wochen zeigen die Suchbegriffe, was sich lohnt.</p>
                <div class="row" style="grid-column:1/-1">
                    <button class="btn btn-primary" type="submit">Kampagne starten <span class="spin" wire:loading wire:target="createCampaign"></span></button>
                    <button class="btn" type="button" wire:click="$set('newProductId', null)">Abbrechen</button>
                </div>
            </div>
        </form>
    @endif

    @php $acos = $totals['sales'] > 0 ? $totals['cost'] / $totals['sales'] * 100 : null; @endphp
    <div class="kpis">
        <div class="kpi"><div class="label">Werbekosten (30 Tage)</div><div class="value">{{ Format::money($totals['cost'], 0) }}</div></div>
        <div class="kpi"><div class="label">Umsatz durch Werbung</div><div class="value">{{ Format::money($totals['sales'], 0) }}</div></div>
        <div class="kpi"><div class="label">ACoS (Ziel {{ Format::percent($target) }})</div><div class="value" @style(['color: var(--warn-fg)' => $acos !== null && $acos > $target])>{{ Format::percent($acos) }}</div></div>
        <div class="kpi"><div class="label">Je 1 € Werbung</div><div class="value">{{ $totals['cost'] > 0 ? Format::money($totals['sales'] / $totals['cost']) : '–' }}</div></div>
        <div class="kpi"><div class="label">Bestellungen</div><div class="value">{{ $totals['orders'] }}</div></div>
    </div>

    <div class="card">
        <div class="tabs" role="tablist">
            @foreach (['vorschlaege' => 'Vorschläge', 'kampagnen' => 'Kampagnen', 'suchbegriffe' => 'Suchbegriffe', 'verlauf' => 'Verlauf'] as $k => $label)
                <button class="tab" role="tab" aria-selected="{{ $tab === $k ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $k }}')">{{ $label }}</button>
            @endforeach
        </div>
        <div class="card-pad">
        @if ($tab === 'vorschlaege')
            @forelse ($suggestions as $s)
                <div class="chance-row" style="padding-left:0;padding-right:0" wire:key="sg-{{ $s['type'] }}-{{ $s['id'] }}">
                    <span class="badge {{ in_array($s['type'], ['promote', 'bid_up', 'budget_up']) ? 'badge-ok' : 'badge-warn' }}">{{ ['negate' => 'Kosten sparen', 'promote' => 'Mehr verkaufen', 'bid_down' => 'Kosten sparen', 'bid_up' => 'Mehr verkaufen', 'budget_up' => 'Mehr verkaufen', 'pause' => 'Kosten sparen'][$s['type']] }}</span>
                    <span class="chance-title"><strong>{{ $s['title'] }}</strong><br><span class="small muted">{{ $s['text'] }}</span></span>
                    <button class="btn btn-sm btn-primary" wire:click="apply('{{ $s['type'] }}', {{ $s['id'] }})">Umsetzen</button>
                    @if (in_array($s['type'], ['negate', 'promote']))<button class="linkbtn small" wire:click="ignoreTerm({{ $s['id'] }})">Ignorieren</button>@endif
                </div>
            @empty
                <div class="empty">Gerade keine Vorschläge. Mit mehr Klicks und Verkäufen werden die Vorschläge genauer.</div>
            @endforelse
            <p class="muted small">Regeln: ab {{ \App\Services\Ads\AdsAdvisor::MIN_CLICKS }} Klicks ohne Verkauf ausschließen, Suchbegriffe mit 2 Verkäufen unter Ziel-ACoS als Keyword, Gebote bei 1,5-fachem Ziel-ACoS senken, Budget erhöhen, wenn es täglich aufgebraucht ist und sich rechnet.</p>

        @elseif ($tab === 'kampagnen')
            <div class="table-wrap">
            <table class="list">
                <thead><tr><th>Kampagne</th><th>Status</th><th>Tagesbudget</th><th class="num">Kosten</th><th class="num">Umsatz</th><th class="num">ACoS</th><th class="num">Klicks</th><th class="num">Bestellungen</th></tr></thead>
                <tbody>
                @forelse ($campaigns as $c)
                    @php $s = $stats[$c->campaign_id] ?? null; @endphp
                    <tr wire:key="c{{ $c->id }}">
                        <td style="min-width:220px"><strong>{{ $c->name }}</strong><div class="small muted">{{ $c->targeting === 'AUTO' ? 'automatisch' : 'manuell' }}</div></td>
                        <td><button class="btn btn-sm" wire:click="toggleCampaign({{ $c->id }})">{{ $c->state === 'ENABLED' ? 'läuft · pausieren' : 'pausiert · starten' }}</button></td>
                        <td class="nowrap"><input class="input input-sm num" style="width:80px" wire:model="budgets.{{ $c->id }}" inputmode="decimal" aria-label="Tagesbudget {{ $c->name }}"> <button class="btn btn-sm" wire:click="saveBudget({{ $c->id }})">OK</button></td>
                        <td class="num">{{ Format::money($s['cost'] ?? 0) }}</td>
                        <td class="num">{{ Format::money($s['sales'] ?? 0) }}</td>
                        <td class="num" @style(['color: var(--warn-fg)' => ($s['acos'] ?? 0) > $target])>{{ Format::percent($s['acos'] ?? null) }}</td>
                        <td class="num">{{ $s['clicks'] ?? 0 }}</td>
                        <td class="num">{{ $s['orders'] ?? 0 }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="empty">Noch keine Kampagnen. Im Chancenfinder unter „Mit Werbung“ siehst du, wo sich eine lohnt.</td></tr>
                @endforelse
                </tbody>
            </table>
            </div>

        @elseif ($tab === 'suchbegriffe')
            <div class="table-wrap">
            <table class="list">
                <thead><tr><th>Suchbegriff</th><th>Kampagne</th><th class="num">Klicks</th><th class="num">Kosten</th><th class="num">Umsatz</th><th class="num">ACoS</th><th>Status</th></tr></thead>
                <tbody>
                @forelse ($terms as $t)
                    @php $a = $t->sales > 0 ? $t->cost / $t->sales * 100 : null; @endphp
                    <tr>
                        <td><strong>{{ $t->search_term }}</strong>@if ($t->keyword)<div class="small muted">über {{ $t->keyword }} ({{ strtolower((string) $t->match_type) }})</div>@endif</td>
                        <td class="small">{{ \Illuminate\Support\Str::limit($campaignNames[$t->campaign_id] ?? $t->campaign_id, 40) }}</td>
                        <td class="num">{{ $t->clicks }}</td>
                        <td class="num">{{ Format::money($t->cost) }}</td>
                        <td class="num">{{ Format::money($t->sales) }}</td>
                        <td class="num" @style(['color: var(--warn-fg)' => $a === null && $t->cost > 0 || ($a ?? 0) > $target])>{{ $a === null ? ($t->cost > 0 ? 'kein Verkauf' : '–') : Format::percent($a) }}</td>
                        <td class="small">{{ ['negative' => 'ausgeschlossen', 'keyword' => 'als Keyword', 'ignored' => 'ignoriert'][$t->handled] ?? '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty">Noch keine Suchbegriffe gelesen.</td></tr>
                @endforelse
                </tbody>
            </table>
            </div>

        @else
            @php $max = max(1, $daily->max('sales'), $daily->max('cost')); @endphp
            <div class="row small" style="margin-bottom:8px">
                <span class="row" style="gap:6px"><span class="legend-swatch" style="background:var(--accent)"></span> Umsatz durch Werbung</span>
                <span class="row" style="gap:6px"><span class="legend-swatch" style="background:var(--warn)"></span> Werbekosten</span>
            </div>
            <div class="daychart" role="img" aria-label="Umsatz und Kosten der Werbung pro Tag">
                @foreach ($daily as $d)
                    <div class="daybar pair" tabindex="0">
                        <span class="fill" style="height: {{ $d->sales / $max * 100 }}%"></span>
                        <span class="fill cost" style="height: {{ $d->cost / $max * 100 }}%"></span>
                        <span class="tip">{{ \Illuminate\Support\Carbon::parse($d->date)->format('d.m.') }}<br>Umsatz {{ Format::money($d->sales, 0) }}<br>Kosten {{ Format::money($d->cost, 0) }}</span>
                    </div>
                @endforeach
            </div>
            @if ($daily->isEmpty())<div class="empty">Noch keine Tageswerte.</div>@endif
        @endif
        </div>
    </div>
    @endif
</div>
