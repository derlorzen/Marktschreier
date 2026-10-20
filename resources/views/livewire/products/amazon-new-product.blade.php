<div class="notice" style="margin-top:12px">
    <h3 style="margin-bottom:6px">Neues Produkt bei Amazon: {{ $variant->product->title }}@if ($variant->label() !== 'Standard') ({{ $variant->label() }})@endif</h3>
    @if ($done)
        <div class="notice notice-ok">{{ $done }}</div>
    @else
        <p class="small muted" style="margin:0 0 10px">Für Produkte, die es bei Amazon noch nicht gibt. Amazon legt eine neue ASIN an und dein Angebot dazu. Varianten-Familien (eine ASIN je Farbe unter einem Elternartikel) legst du vorerst in Seller Central an.</p>
        @if ($error)<div class="notice notice-bad small" style="margin-bottom:10px">{{ $error }}</div>@endif

        <form class="row" wire:submit="searchTypes">
            <input class="input" style="flex:1 1 280px" wire:model="typeQuery" aria-label="Suchbegriff für den Produkttyp">
            <button class="btn btn-sm" type="submit">Produkttyp suchen <span class="spin" wire:loading wire:target="searchTypes"></span></button>
            <button class="btn btn-sm" type="button" wire:click="$dispatch('close-amazon-new')">Schließen</button>
        </form>
        @if ($types && ! $productType)
            <div class="stack" style="margin-top:10px">
                @foreach ($types as $t)
                    <label class="check"><input type="radio" name="ptype" wire:click="pickType('{{ $t['name'] }}')"> {{ $t['title'] }} <span class="mono small muted">{{ $t['name'] }}</span></label>
                @endforeach
            </div>
        @endif

        @if ($productType)
            <div class="row small" style="margin-top:10px"><strong>Produkttyp: {{ $productType }}</strong><button class="linkbtn" wire:click="$set('productType', '')">ändern</button></div>
            <div class="grid-2" style="margin-top:10px">
                @foreach ($fields as $f)
                    <div class="field" wire:key="f-{{ $f['name'] }}">
                        <span>{{ $f['title'] }} <span class="hint mono">{{ $f['name'] }}</span></span>
                        @if ($f['kind'] === 'simple')
                            @php $part = $f['parts'][0]; @endphp
                            @if ($part['enum'])
                                <select class="input input-sm" wire:model="values.{{ $f['name'] }}">
                                    <option value="">Bitte wählen</option>
                                    @foreach ($part['enum'] as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
                                </select>
                            @elseif (in_array($f['name'], ['product_description']))
                                <textarea class="input" rows="4" wire:model="values.{{ $f['name'] }}"></textarea>
                            @else
                                <input class="input input-sm" wire:model="values.{{ $f['name'] }}" @if ($part['max']) maxlength="{{ $part['max'] }}" @endif>
                            @endif
                        @elseif ($f['kind'] === 'group')
                            <div class="row" style="gap:6px">
                                @foreach ($f['parts'] as $part)
                                    @if ($part['enum'])
                                        <select class="input input-sm" style="flex:1" wire:model="values.{{ $f['name'] }}.{{ $part['name'] }}" aria-label="{{ $part['title'] }}">
                                            <option value="">{{ $part['title'] }}</option>
                                            @foreach ($part['enum'] as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach
                                        </select>
                                    @else
                                        <input class="input input-sm" style="flex:1" wire:model="values.{{ $f['name'] }}.{{ $part['name'] }}" placeholder="{{ $part['title'] }}" aria-label="{{ $part['title'] }}">
                                    @endif
                                @endforeach
                            </div>
                        @else
                            <textarea class="input mono small" rows="3" wire:model="values.{{ $f['name'] }}" placeholder='z. B. [{"value": …}] laut Amazon-Vorgabe'></textarea>
                            <span class="hint">Verschachtelte Angabe, als JSON. Leer lassen, wenn Amazon sie nicht verlangt.</span>
                        @endif
                    </div>
                @endforeach
            </div>
            @if ($check)
                @if ($check['errors'])
                    <div class="notice notice-bad small" style="margin-top:10px"><strong>Amazon meldet:</strong><ul style="margin:4px 0 0">@foreach ($check['errors'] as $e)<li>{{ $e }}</li>@endforeach</ul></div>
                @elseif ($check['accepted'])
                    <div class="notice notice-ok small" style="margin-top:10px">Amazon hat die Angaben geprüft, keine Fehler.</div>
                @endif
                @if ($check['warnings'])<ul class="small" style="color:var(--warn-fg)">@foreach ($check['warnings'] as $w)<li>{{ $w }}</li>@endforeach</ul>@endif
            @endif
            <div class="row" style="margin-top:12px">
                <button class="btn" wire:click="validateWithAmazon">Bei Amazon prüfen <span class="spin" wire:loading wire:target="validateWithAmazon"></span></button>
                <button class="btn btn-primary" wire:click="create" @disabled(! ($check['accepted'] ?? false))>Anlegen <span class="spin" wire:loading wire:target="create"></span></button>
            </div>
        @endif
    @endif
</div>
