<div>
    <div class="page-head">
        <h1>Kalender</h1>
        <span class="muted small">Set-Releases, Vorbestellungen, Aktionen</span>
        <span class="spacer"></span>
        <label class="check"><input type="checkbox" wire:model.live="showPast"> Vergangene zeigen</label>
        <button class="btn btn-primary" wire:click="create">Termin hinzufügen</button>
    </div>

    @if ($editId !== null)
        <form class="card" wire:submit="save" style="margin-bottom:16px">
            <div class="card-head"><h2>{{ $editId ? 'Termin bearbeiten' : 'Neuer Termin' }}</h2></div>
            <div class="card-pad grid-3">
                <label class="field span-2">Titel <input class="input" wire:model="form.title" placeholder="z. B. Pokémon TCG: neues Set" autofocus>
                    @error('form.title') <span class="error">{{ $message }}</span> @enderror</label>
                <label class="field">Datum <input class="input" type="date" wire:model="form.starts_on">@error('form.starts_on') <span class="error">{{ $message }}</span> @enderror</label>
                <label class="field">Art
                    <select class="input" wire:model="form.type">@foreach (\App\Models\CalendarEvent::TYPES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
                <label class="field">Erinnern, Tage vorher <input class="input num" type="number" min="0" max="90" wire:model="form.remind_days"></label>
                <div></div>
                <label class="field span-2">Notizen <span class="hint">z. B. Nachbestellung, Werbung starten, Sleeves passend zum Set</span>
                    <textarea class="input" rows="3" wire:model="form.notes"></textarea></label>
                <div class="field">Passende Artikel
                    <input class="input" wire:model.live.debounce.300ms="productSearch" placeholder="Artikel suchen">
                    @foreach ($results as $r)<button type="button" class="btn btn-sm" style="margin-top:4px;justify-content:flex-start" wire:click="addProduct({{ $r->id }})">+ {{ \Illuminate\Support\Str::limit($r->title, 40) }}</button>@endforeach
                    <div class="row" style="margin-top:6px;gap:6px">
                        @foreach ($form['product_ids'] ?? [] as $pid)<span class="badge">{{ \Illuminate\Support\Str::limit($productNames[$pid] ?? '?', 30) }} <button type="button" class="linkbtn" wire:click="removeProduct({{ $pid }})" aria-label="entfernen">✕</button></span>@endforeach
                    </div>
                </div>
                <div class="row" style="grid-column:1/-1">
                    <button class="btn btn-primary" type="submit">Speichern</button>
                    <button class="btn" type="button" wire:click="$set('editId', null)">Abbrechen</button>
                </div>
            </div>
        </form>
    @endif

    @forelse ($groups as $month => $events)
        <h2 style="margin:18px 0 8px">{{ $month }}</h2>
        @foreach ($events as $e)
            @php $d = $e->daysUntil(); @endphp
            <article class="card alert-card {{ $d >= 0 && $d <= $e->remind_days ? 'lvl-warn' : '' }}" wire:key="ev{{ $e->id }}">
                <div class="cal-date"><strong>{{ $e->starts_on->format('d.') }}</strong><span>{{ $e->starts_on->locale('de')->translatedFormat('D') }}</span></div>
                <div style="flex:1;min-width:0">
                    <div class="row small" style="gap:6px;margin-bottom:4px">
                        <span class="badge {{ $e->type === 'release' ? 'badge-ok' : '' }}">{{ \App\Models\CalendarEvent::TYPES[$e->type] ?? $e->type }}</span>
                        <span class="muted">{{ $d === 0 ? 'heute' : ($d > 0 ? "in {$d} Tagen" : 'vorbei') }}</span>
                    </div>
                    <h3>{{ $e->title }}</h3>
                    @if ($e->notes)<p class="opp-text" style="white-space:pre-line">{{ $e->notes }}</p>@endif
                    @if ($e->product_ids)
                        <div class="row small" style="gap:6px;margin-top:6px">
                            @foreach ($e->product_ids as $pid)@if (isset($productNames[$pid]))<a class="badge" href="{{ route('products.edit', $pid) }}" wire:navigate>{{ \Illuminate\Support\Str::limit($productNames[$pid], 30) }}</a>@endif @endforeach
                        </div>
                    @endif
                </div>
                <div class="row" style="align-self:center">
                    <button class="btn btn-sm" wire:click="edit({{ $e->id }})">Bearbeiten</button>
                    <button class="linkbtn small" wire:click="delete({{ $e->id }})" wire:confirm="Termin löschen?">Löschen</button>
                </div>
            </article>
        @endforeach
    @empty
        <div class="card empty">Noch keine Termine. Trag kommende Set-Releases ein, Marktschreier erinnert rechtzeitig an Nachbestellung und Werbung.</div>
    @endforelse
</div>
