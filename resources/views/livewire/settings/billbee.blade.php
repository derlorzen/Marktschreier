<x-settings-shell active="settings.billbee" title="Billbee" intro="Nur lesend: Einkaufspreis, Bestand und Verkäufe. Billbee bleibt führend für Bestand und Aufträge, Marktschreier schreibt dort nichts.">
    @if ($notice)
        <div class="notice notice-ok row" role="status" style="margin-bottom:16px"><span>{{ $notice }}</span>
            <button class="linkbtn" style="margin-left:auto" wire:click="$set('notice', null)">Ausblenden</button></div>
    @endif
    <form class="card" wire:submit="save">
        <div class="card-head"><h2>Zugang</h2></div>
        <div class="card-pad grid-2">
            <label class="field">API-Key <span class="hint">{{ $hasKey ? 'gespeichert, leer lassen zum Behalten' : 'bei Billbee anzufragen' }}</span>
                <input class="input mono" type="password" wire:model="billbeeApiKey" placeholder="{{ $hasKey ? '••••••••' : '' }}" autocomplete="new-password">
            </label>
            <label class="field">Benutzer <span class="hint">E-Mail des Billbee-Kontos</span>
                <input class="input" wire:model="billbeeUser" autocomplete="off">
            </label>
            <label class="field">API-Passwort <span class="hint">{{ $hasPassword ? 'gespeichert, leer lassen zum Behalten' : 'in Billbee unter Einstellungen, API festlegen' }}</span>
                <input class="input mono" type="password" wire:model="billbeePassword" placeholder="{{ $hasPassword ? '••••••••' : '' }}" autocomplete="new-password">
            </label>
            <label class="field">Einkaufspreis in Billbee ist gepflegt als
                <select class="input" wire:model="costMode">
                    <option value="net">netto</option>
                    <option value="gross">brutto (wird mit der MwSt. aus „Allgemein“ umgerechnet)</option>
                </select>
            </label>
            <div class="span-2 row">
                <button class="btn btn-primary" type="submit">Speichern</button>
                <button type="button" class="btn" wire:click="testConnection">Verbindung testen <span class="spin" wire:loading wire:target="testConnection"></span></button>
                @if ($test)<span @class(['badge', 'badge-ok' => $test['ok'], 'badge-bad' => ! $test['ok']]) style="white-space:normal">{{ $test['text'] }}</span>@endif
            </div>
        </div>
    </form>
    <div class="card card-pad">
        <dl class="kv">
            <dt>EK und Bestand</dt><dd>{{ $lastStock ? 'zuletzt '.$lastStock->finished_at->timezone('Europe/Berlin')->format('d.m.Y H:i') : 'noch nicht abgeglichen' }}</dd>
            <dt>Verkäufe</dt><dd>{{ $lastOrders ? 'zuletzt '.$lastOrders->finished_at->timezone('Europe/Berlin')->format('d.m.Y H:i') : 'noch nicht eingelesen' }}</dd>
            <dt>Zuordnung</dt><dd>über die SKU, sonst über die GTIN. Bestellungen ohne Kundendaten.</dd>
        </dl>
    </div>
</x-settings-shell>
