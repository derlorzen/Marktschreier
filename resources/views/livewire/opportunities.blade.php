@php use App\Services\Opportunities\Opportunity; use App\Support\Format; @endphp
<div>
    <div class="page-head">
        <h1>Chancen</h1>
        <span class="spacer"></span>
        <div class="seg" role="group" aria-label="Art der Chancen">
            <button type="button" @class(['seg-btn', 'is-active' => $kind === 'free']) aria-pressed="{{ $kind === 'free' ? 'true' : 'false' }}" wire:click="$set('kind', 'free')">Ohne Werbebudget · {{ $counts['free'] }}</button>
            <button type="button" @class(['seg-btn', 'is-active' => $kind === 'ads']) aria-pressed="{{ $kind === 'ads' ? 'true' : 'false' }}" wire:click="$set('kind', 'ads')">Mit Werbung · {{ $counts['ads'] }}</button>
        </div>
    </div>

    <div class="notice" style="margin-bottom:14px">
        @if ($kind === 'free')
            Was ohne Werbegeld mehr Umsatz oder Gewinn bringt: fehlende Listings, unvollständige Daten, Preise mit zu wenig Gewinn, Bestände.
        @else
            Wo sich Werbung rechnet: Artikel, die sich schon ohne Anzeigen verkaufen, genug Gewinn je Verkauf bringen und genug Bestand haben. Mit der Werbekosten-Grenze siehst du, ab wann eine Anzeige Verlust macht.
        @endif
        @if ($value > 0)
            <strong>Geschätzt bis zu {{ Format::money($value, 0) }} mehr Gewinn im Monat.</strong>
        @endif
        <span class="muted small">Schätzungen auf Basis der Verkäufe der letzten 90 Tage, als Richtwert gedacht.</span>
    </div>

    @if ($notice)
        <div class="notice notice-ok row" role="status" style="margin-bottom:14px"><span>{{ $notice }}</span>
            <button class="linkbtn" style="margin-left:auto" wire:click="$set('notice', null)">Ausblenden</button></div>
    @endif

    <div class="row" style="margin-bottom:14px">
        <button type="button" @class(['btn btn-sm', 'btn-primary' => $type === '']) wire:click="$set('type', '')">Alle</button>
        @foreach ($types as $t => $n)
            <button type="button" @class(['btn btn-sm', 'btn-primary' => $type === $t]) wire:click="$set('type', '{{ $t }}')">{{ Opportunity::typeLabel($t) }} · {{ $n }}</button>
        @endforeach
        <label class="check" style="margin-left:auto"><input type="checkbox" wire:model.live="showDismissed"> Ausgeblendete zeigen</label>
    </div>

    @forelse ($list as $o)
        @php $key = $o->key(); $isHidden = isset($dismissed[$key]); @endphp
        <article class="card opp" wire:key="{{ $key }}" @if ($isHidden) style="opacity:.6" @endif>
            <div class="opp-main">
                <div class="row small" style="gap:6px;margin-bottom:4px">
                    <span @class(['badge', 'badge-warn' => in_array($o->type, ['low_profit', 'stockout']), 'badge-ok' => $o->kind === 'ads'])>{{ Opportunity::typeLabel($o->type) }}</span>
                    @if ($o->channel)<span class="badge">{{ $o->channel->name }}</span>@endif
                </div>
                <h3>{{ $o->title }}</h3>
                <p class="opp-text">{{ $o->text }}</p>
                @if ($o->facts)
                    <dl class="facts">
                        @foreach ($o->facts as $label => $val)<div><dt>{{ $label }}</dt><dd>{{ $val }}</dd></div>@endforeach
                    </dl>
                @endif
            </div>
            <div class="opp-side">
                @if ($o->monthlyValue)
                    <div class="opp-value">bis {{ Format::money($o->monthlyValue, 0) }}<span>im Monat</span></div>
                @endif
                @if ($o->actionUrl)<a class="btn btn-sm btn-primary" href="{{ $o->actionUrl }}" wire:navigate>{{ $o->actionLabel }}</a>@endif
                @if ($isHidden)
                    <button class="btn btn-sm" wire:click="restore('{{ $key }}')">Wieder zeigen</button>
                @else
                    <button class="btn btn-sm" wire:click="done('{{ $key }}')">Erledigt</button>
                    <button class="linkbtn small" wire:click="ignore('{{ $key }}')">Nicht relevant</button>
                @endif
            </div>
        </article>
    @empty
        <div class="card empty">
            @if (! \App\Models\Order::exists())
                <h2 style="color:var(--ink)">Noch keine Verkäufe</h2>
                <p>Die meisten Chancen brauchen die Verkäufe aus Billbee. Unter <a href="{{ route('sync') }}" wire:navigate>Abgleich</a> „Verkäufe holen“ starten.</p>
            @else
                Gerade keine Chancen in diesem Bereich.
            @endif
        </div>
    @endforelse
    @if ($total > 200)<p class="muted small">Die 200 wichtigsten von {{ $total }} Chancen.</p>@endif
</div>
