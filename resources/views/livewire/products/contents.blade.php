<div>
    <div class="row" style="margin-bottom:12px">
        <div class="seg" role="group" aria-label="Kanal">
            @foreach ($channels as $ch)
                <button type="button" @class(['seg-btn', 'is-active' => $channelKey === $ch->key]) wire:click="$set('channelKey', '{{ $ch->key }}')">{{ $ch->name }}</button>
            @endforeach
        </div>
        <span class="muted small">Leere Felder übernehmen die Stammdaten.</span>
        <span style="flex:1"></span>
        @if ($aiReady)
            <button class="btn btn-sm" wire:click="suggest" wire:loading.attr="disabled">KI-Vorschlag <span class="spin" wire:loading wire:target="suggest"></span></button>
        @else
            @can('admin')<a class="small" href="{{ route('settings.general') }}" wire:navigate>KI-Vorschläge einrichten</a>@endcan
        @endif
    </div>
    @if ($notice)<div class="notice notice-ok row" style="margin-bottom:12px"><span>{{ $notice }}</span><button class="linkbtn" style="margin-left:auto" wire:click="$set('notice', null)">Ausblenden</button></div>@endif
    @if ($error)<div class="notice notice-bad" style="margin-bottom:12px">{{ $error }}</div>@endif

    <div class="stack">
        <label class="field" x-data="{ n: {{ mb_strlen($form['title']) }} }">Titel auf {{ $channel->name }} <span class="hint"><span x-text="n"></span> von {{ $limits['title'] }} Zeichen</span>
            <input class="input" wire:model="form.title" x-on:input="n = $event.target.value.length" placeholder="{{ $product->title }}" maxlength="{{ $limits['title'] }}">
            @error('form.title') <span class="error">{{ $message }}</span> @enderror
        </label>
        @if (($limits['bullets'] ?? 0) > 0)
            <div class="field">Stichpunkte <span class="hint">je höchstens {{ $limits['bullet'] }} Zeichen, wichtigster Nutzen zuerst</span>
                @foreach ($form['bullets'] as $i => $b)
                    <input class="input" style="margin-top:6px" wire:model="form.bullets.{{ $i }}" maxlength="{{ $limits['bullet'] }}" aria-label="Stichpunkt {{ $i + 1 }}" placeholder="Stichpunkt {{ $i + 1 }}">
                    @error("form.bullets.$i") <span class="error">{{ $message }}</span> @enderror
                @endforeach
            </div>
            <label class="field">Versteckte Suchbegriffe <span class="hint">höchstens {{ $limits['search_terms'] }} Zeichen, mit Leerzeichen getrennt, ohne Marke</span>
                <input class="input" wire:model="form.search_terms" maxlength="{{ $limits['search_terms'] }}">
                @error('form.search_terms') <span class="error">{{ $message }}</span> @enderror
            </label>
        @endif
        <label class="field">Beschreibung <span class="hint">HTML, leer = Beschreibung aus den Stammdaten</span>
            <textarea class="input mono" rows="10" wire:model="form.description_html"></textarea>
        </label>
        <div class="row">
            <button class="btn btn-primary" wire:click="save">Speichern</button>
            @if ($canPush)<button class="btn" wire:click="push">Speichern und zu {{ $channel->name }} übertragen <span class="spin" wire:loading wire:target="push"></span></button>@endif
            @if ($content->pushed_at)<span class="muted small">Zuletzt übertragen {{ $content->pushed_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</span>@endif
            @if ($content->source === 'ai')<span class="badge">mit KI erstellt</span>@endif
        </div>
    </div>
</div>
