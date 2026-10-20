<div>
    <x-article-tabs />
    <div class="page-head"><h1>Neuer Artikel</h1><span class="muted small">wird zuerst als Entwurf angelegt</span></div>
    <form wire:submit="save">
        <div class="card">
            <div class="card-head"><h2>Stammdaten</h2></div>
            <div class="card-pad grid-2">
                <label class="field span-2">Titel
                    <input class="input" wire:model="title" maxlength="255" autofocus>
                    @error('title') <span class="error">{{ $message }}</span> @enderror
                </label>
                <label class="field">Marke <input class="input" wire:model="vendor"></label>
                <label class="field">Produktart <input class="input" wire:model="productType"></label>
                <label class="field span-2">Beschreibung <span class="hint">einfacher Text, später im Artikel als HTML bearbeitbar</span>
                    <textarea class="input" rows="5" wire:model="description"></textarea>
                </label>
                <label class="field span-2">Tags <span class="hint">mit Komma trennen</span><input class="input" wire:model="tags"></label>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>Varianten</h2><span class="muted small">Optionen wie Farbe oder Größe, Werte mit Komma trennen. Ohne Optionen gibt es eine Variante.</span></div>
            <div class="card-pad stack">
                @foreach ($options as $i => $o)
                    <div class="row" wire:key="opt{{ $i }}">
                        <input class="input" style="flex:1 1 160px" wire:model.live.debounce.400ms="options.{{ $i }}.name" placeholder="Option, z. B. Farbe" aria-label="Name der Option {{ $i + 1 }}">
                        <input class="input" style="flex:3 1 300px" wire:model.live.debounce.400ms="options.{{ $i }}.values" placeholder="Werte, z. B. Schwarz, Rot, Blau" aria-label="Werte der Option {{ $i + 1 }}">
                        <button type="button" class="btn btn-sm" wire:click="removeOption({{ $i }})">Entfernen</button>
                    </div>
                @endforeach
                <div class="row">
                    @if (count($options) < 3)<button type="button" class="btn btn-sm" wire:click="addOption">Option hinzufügen</button>@endif
                    <label class="field" style="flex-direction:row;align-items:center;gap:8px">Preis für alle <input class="input input-sm num" style="width:110px" wire:model.live.debounce.400ms="defaultPrice" inputmode="decimal" placeholder="9,90"></label>
                </div>
                <div class="table-wrap">
                <table class="list">
                    <thead><tr><th>Variante</th><th>SKU</th><th>GTIN/EAN</th><th class="num">Preis</th><th><span class="sr-only">Entfernen</span></th></tr></thead>
                    <tbody>
                    @foreach ($rows as $key => $row)
                        <tr wire:key="row-{{ md5($key) }}">
                            <td class="nowrap"><strong>{{ $key }}</strong></td>
                            <td><input class="input input-sm mono" wire:model="rows.{{ $key }}.sku" aria-label="SKU {{ $key }}">@error("rows.$key.sku") <div class="error">{{ $message }}</div> @enderror</td>
                            <td><input class="input input-sm mono" wire:model="rows.{{ $key }}.barcode" inputmode="numeric" aria-label="GTIN {{ $key }}">@error("rows.$key.barcode") <div class="error">{{ $message }}</div> @enderror</td>
                            <td style="width:120px"><input class="input input-sm num" wire:model="rows.{{ $key }}.price" inputmode="decimal" aria-label="Preis {{ $key }}">@error("rows.$key.price") <div class="error">{{ $message }}</div> @enderror</td>
                            <td class="row-remove">
                                @if (count($rows) > 1)
                                    <button type="button" class="btn btn-sm btn-icon" wire:click="removeRow(@js($key))" title="{{ $key }} gibt es nicht" aria-label="Variante {{ $key }} entfernen">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
                @error('rows') <div class="error">{{ $message }}</div> @enderror
                @if ($excluded)
                    <div class="row small" wire:key="excluded">
                        <span class="muted">Entfernt:</span>
                        @foreach ($excluded as $key)
                            <button type="button" class="btn btn-sm" wire:key="ex-{{ md5($key) }}" wire:click="restoreRow(@js($key))" aria-label="Variante {{ $key }} wiederherstellen">{{ $key }} <span aria-hidden="true">↺</span></button>
                        @endforeach
                        @if (count($excluded) > 1)
                            <button type="button" class="linkbtn" wire:click="restoreAll">Alle wiederherstellen</button>
                        @endif
                    </div>
                @endif
            </div>
        </div>
        <div class="row" style="margin-top:16px">
            <button class="btn btn-primary" type="submit">Anlegen <span class="spin" wire:loading wire:target="save"></span></button>
            <a class="btn" href="{{ route('products.index') }}" wire:navigate>Abbrechen</a>
        </div>
    </form>
</div>
