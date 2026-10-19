<div>
    <div class="page-head">
        <h1>Kanäle und Preisregeln</h1>
        <span class="spacer"></span>
        <button class="btn btn-primary" wire:click="save">Speichern <span class="spin" wire:loading wire:target="save"></span></button>
    </div>

    @if ($notice)
        <div class="notice notice-ok row" role="status" style="margin-bottom:14px"><span>{{ $notice }}</span>
            <button class="linkbtn" style="margin-left:auto" wire:click="$set('notice', null)">Ausblenden</button></div>
    @endif

    <div class="notice" style="margin-bottom:16px">
        <strong>So funktioniert es:</strong> Ein Regelwerk rechnet aus dem Netto-Einkaufspreis aus Billbee den Verkaufspreis je Kanal.
        Es greift nur, wenn du es anklickst, im Artikel unter „Preise je Kanal“ oder für viele Artikel über die Gruppenfunktion.
        Es setzt die Preise einmal und bleibt danach nicht am Artikel hängen. Marktplätze dürfen den Preis danach wieder ändern.
    </div>

    <div class="card">
        <div class="card-head"><h2>Kanäle</h2><span class="muted small">Kosten je Verkauf, für Gewinn und Preisberechnung</span></div>
        <div class="table-wrap">
            <table class="list">
                <thead><tr><th>Aktiv</th><th>Name</th><th class="num">Gebühr in %</th><th class="num">Feste Gebühr €</th><th class="num">Versand und Verpackung €</th><th>Plattform in Billbee</th></tr></thead>
                <tbody>
                @foreach ($channels as $id => $c)
                    <tr wire:key="c{{ $id }}">
                        <td><input type="checkbox" class="check" wire:model="channels.{{ $id }}.active" aria-label="{{ $c['name'] }} aktiv"></td>
                        <td style="min-width:150px"><input class="input input-sm" wire:model="channels.{{ $id }}.name" aria-label="Name">
                            @error("channels.$id.name") <div class="error">{{ $message }}</div> @enderror</td>
                        <td style="width:120px"><input class="input input-sm num" wire:model="channels.{{ $id }}.fee_percent" inputmode="decimal" aria-label="Gebühr in Prozent {{ $c['name'] }}">
                            @error("channels.$id.fee_percent") <div class="error">{{ $message }}</div> @enderror</td>
                        <td style="width:120px"><input class="input input-sm num" wire:model="channels.{{ $id }}.fee_fixed" inputmode="decimal" aria-label="Feste Gebühr {{ $c['name'] }}"></td>
                        <td style="width:150px"><input class="input input-sm num" wire:model="channels.{{ $id }}.shipping_cost" inputmode="decimal" aria-label="Versandkosten {{ $c['name'] }}"></td>
                        <td style="min-width:160px"><input class="input input-sm" wire:model="channels.{{ $id }}.billbee_platforms" placeholder="z. B. amazon" aria-label="Plattformname in Billbee {{ $c['name'] }}"></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="muted small" style="padding:0 18px 14px;margin:0">Gebühren sind Beispielwerte, bitte mit den eigenen Abrechnungen abgleichen. Amazon-Versandgebühren (FBA) als feste Gebühr eintragen. „Plattform in Billbee“ ordnet Bestellungen dem Kanal zu, mehrere Namen mit Komma trennen.</p>
    </div>

    @foreach ($rules as $rid => $r)
        <div class="card" wire:key="r{{ $rid }}">
            <div class="card-head">
                <input class="input" style="max-width:320px;font-weight:600" wire:model="rules.{{ $rid }}.name" aria-label="Name des Regelwerks">
                <span class="spacer" style="flex:1"></span>
                <button class="btn btn-sm" wire:click="deleteRule({{ $rid }})" wire:confirm="Regelwerk „{{ $r['name'] }}“ löschen?">Löschen</button>
            </div>
            <div class="card-pad stack">
                <div class="grid-2">
                    <label class="field">Beschreibung <span class="hint">wofür das Regelwerk gedacht ist</span>
                        <input class="input" wire:model="rules.{{ $rid }}.description" placeholder="z. B. Eigenmarke, Kleinteile unter 3 € EK">
                    </label>
                    <label class="field">Mindestgewinn je Verkauf in € <span class="hint">hat Vorrang vor der Zielmarge</span>
                        <input class="input num" style="max-width:160px" wire:model="rules.{{ $rid }}.min_profit" inputmode="decimal">
                    </label>
                </div>
                <div class="table-wrap">
                <table class="list">
                    <thead><tr><th>Kanal</th><th class="num">Zielmarge in % vom Nettopreis</th><th>Preis endet auf</th><th class="muted">Beispiel bei 5,00 € EK</th></tr></thead>
                    <tbody>
                    @foreach ($channels as $cid => $c)
                        @php $example = $examples[$rid][$cid] ?? null; @endphp
                        <tr wire:key="r{{ $rid }}c{{ $cid }}" @if (! $c['active']) style="opacity:.55" @endif>
                            <td>{{ $c['name'] }}</td>
                            <td style="width:200px"><input class="input input-sm num" wire:model.live.debounce.500ms="rules.{{ $rid }}.lines.{{ $cid }}.target_margin" inputmode="decimal" placeholder="nicht berechnen" aria-label="Zielmarge {{ $c['name'] }}">
                                @error("rules.$rid.lines.$cid.target_margin") <div class="error">{{ $message }}</div> @enderror</td>
                            <td style="width:150px">
                                <select class="input input-sm" wire:model.live="rules.{{ $rid }}.lines.{{ $cid }}.ending" aria-label="Endung {{ $c['name'] }}">
                                    <option value="99">,99</option><option value="95">,95</option><option value="90">,90</option><option value="00">,00</option>
                                </select>
                            </td>
                            <td class="muted small">
                                @if ($example)
                                    {{ \App\Support\Format::money($example['price']) }}, Gewinn {{ \App\Support\Format::money($example['profit']) }}
                                @else
                                    –
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    @endforeach

    <div class="row" style="margin-top:16px">
        <button class="btn" wire:click="addRule">Regelwerk hinzufügen</button>
        <button class="btn btn-primary" wire:click="save">Speichern</button>
    </div>
</div>
