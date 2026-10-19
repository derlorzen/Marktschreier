<div>
    <div class="page-head">
        <h1>Hinweise</h1>
        <span class="spacer"></span>
        <div class="seg" role="group" aria-label="Ansicht">
            <button type="button" @class(['seg-btn', 'is-active' => $view === 'offen']) wire:click="$set('view', 'offen')">Offen · {{ $openCount }}</button>
            <button type="button" @class(['seg-btn', 'is-active' => $view === 'woche']) wire:click="$set('view', 'woche')">Wochenrückblick</button>
            <button type="button" @class(['seg-btn', 'is-active' => $view === 'erledigt']) wire:click="$set('view', 'erledigt')">Erledigt</button>
        </div>
    </div>

    @if ($view === 'woche' && $review)
        <div class="card card-pad stack">
            <h2>Deine Woche: {{ $review['period'] }}</h2>
            <div class="kpis" style="margin:0">
                <div class="kpi"><div class="label">Umsatz</div><div class="value">{{ $review['revenue'] }}</div><div class="small muted">{{ $review['revenue_change'] }}</div></div>
                <div class="kpi"><div class="label">Gewinn</div><div class="value">{{ $review['profit'] }}</div><div class="small muted">{{ $review['profit_change'] }}</div></div>
                <div class="kpi"><div class="label">Bestellungen</div><div class="value">{{ $review['orders'] }}</div></div>
            </div>
            @if ($review['channels'])
                <table class="list"><thead><tr><th>Kanal</th><th class="num">Umsatz</th><th class="num">Gewinn</th></tr></thead><tbody>
                    @foreach ($review['channels'] as $c)<tr><td>{{ $c['name'] }}</td><td class="num">{{ $c['revenue'] }}</td><td class="num">{{ $c['profit'] }}</td></tr>@endforeach
                </tbody></table>
            @endif
            @if ($review['top'])<p style="margin:0"><strong>Bestseller:</strong> {{ implode(', ', $review['top']) }}</p>@endif
            @if ($review['chances'])
                <div><strong>Die wichtigsten Chancen</strong><ul style="margin:6px 0 0">@foreach ($review['chances'] as $c)<li>{{ $c }}</li>@endforeach</ul></div>
            @endif
            <p class="muted small" style="margin:0">Diesen Rückblick gibt es jeden Montag früh per Mail, einstellbar unter Mein Konto.</p>
        </div>
    @else
        @if ($view === 'offen' && $alerts->isNotEmpty())
            <div class="row" style="margin-bottom:12px"><button class="btn btn-sm" wire:click="markAllRead">Alle als gelesen markieren</button></div>
        @endif
        @forelse ($alerts as $a)
            @php $unread = ! $a->resolved_at && ! isset($readIds[$a->id]); @endphp
            <article class="card alert-card {{ 'lvl-'.$a->level }}" wire:key="al{{ $a->id }}" @if (! $unread) style="opacity:.75" @endif>
                <div style="flex:1;min-width:0">
                    <div class="row small" style="gap:6px;margin-bottom:4px">
                        <span @class(['badge', 'badge-bad' => $a->level === 'bad', 'badge-warn' => $a->level === 'warn'])>{{ \App\Models\Alert::typeLabel($a->type) }}</span>
                        <span class="muted">{{ $a->updated_at->timezone('Europe/Berlin')->format('d.m. H:i') }}</span>
                        @if ($unread)<span class="badge badge-ok">neu</span>@endif
                    </div>
                    <h3>{{ $a->title }}</h3>
                    @if ($a->body)<p class="opp-text">{{ \Illuminate\Support\Str::limit($a->body, 400) }}</p>@endif
                </div>
                <div class="row" style="align-self:center">
                    @if ($a->url)<a class="btn btn-sm btn-primary" href="{{ $a->url }}" wire:navigate wire:click="markRead({{ $a->id }})">Ansehen</a>@endif
                    @if (! $a->resolved_at)
                        @if ($unread)<button class="btn btn-sm" wire:click="markRead({{ $a->id }})">Gelesen</button>@endif
                        <button class="btn btn-sm" wire:click="resolve({{ $a->id }})" title="Kommt wieder, falls das Problem weiter besteht">Erledigt</button>
                    @endif
                </div>
            </article>
        @empty
            <div class="card empty">{{ $view === 'erledigt' ? 'Noch nichts erledigt.' : 'Alles in Ordnung, keine offenen Hinweise.' }}</div>
        @endforelse
    @endif
</div>
