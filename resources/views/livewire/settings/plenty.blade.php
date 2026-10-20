<x-settings-shell active="settings.plenty" title="PlentyONE" intro="Andocken an ein bestehendes PlentyONE-System: Artikel, Varianten, Preise, Einkaufspreis, Bestand und Aufträge werden gelesen. PlentyONE bleibt führend, bis der Umzug abgeschlossen ist. Marktschreier schreibt nichts nach PlentyONE.">
    @if ($notice)
        <div class="notice notice-ok row" role="status" style="margin-bottom:16px"><span>{{ $notice }}</span>
            <button class="linkbtn" style="margin-left:auto" wire:click="$set('notice', null)">Ausblenden</button></div>
    @endif
    <form class="card" wire:submit="save">
        <div class="card-head"><h2>Zugang</h2>@if ($count)<span class="muted small">{{ $count }} Artikel aus PlentyONE</span>@endif</div>
        <div class="card-pad grid-2">
            <label class="field span-2">Adresse des Systems <span class="hint">z. B. https://p2241.my.plentysystems.com</span>
                <input class="input" wire:model="url" placeholder="https://…" autocomplete="off">
                @error('url') <span class="error">{{ $message }}</span> @enderror
            </label>
            <label class="field">API-Benutzer <span class="hint">eigener Benutzer mit Leserechten auf Artikel, Bestand, Aufträge</span>
                <input class="input" wire:model="username" autocomplete="off">
            </label>
            <label class="field">Passwort <span class="hint">{{ $hasPassword ? 'gespeichert, leer lassen zum Behalten' : 'noch nicht hinterlegt' }}</span>
                <input class="input" type="password" wire:model="password" placeholder="{{ $hasPassword ? '••••••••' : '' }}" autocomplete="new-password">
            </label>
            <label class="field">Verkaufspreis für den Shop <span class="hint">ID des Verkaufspreises in PlentyONE</span>
                @if ($priceTypes)
                    <select class="input" wire:model="salesPriceId">@foreach ($priceTypes as $id => $name)<option value="{{ $id }}">{{ $id }}: {{ $name }}</option>@endforeach</select>
                @else
                    <input class="input num" style="max-width:120px" wire:model="salesPriceId" inputmode="numeric">
                @endif
                @error('salesPriceId') <span class="error">{{ $message }}</span> @enderror
            </label>
            <label class="field">Lager für den Bestand <span class="hint">IDs mit Komma, leer = alle Lager</span>
                <input class="input" wire:model="warehouseIds" placeholder="z. B. 1">
                @error('warehouseIds') <span class="error">{{ $message }}</span> @enderror
            </label>
            <label class="field">Sprache der Texte
                <input class="input" style="max-width:80px" wire:model="lang" maxlength="2">
            </label>
            <div class="span-2 row">
                <button class="btn btn-primary" type="submit">Speichern</button>
                <button type="button" class="btn" wire:click="testConnection">Verbindung testen <span class="spin" wire:loading wire:target="testConnection"></span></button>
                @if ($test)<span @class(['badge', 'badge-ok' => $test['ok'], 'badge-bad' => ! $test['ok']]) style="white-space:normal">{{ $test['text'] }}</span>@endif
            </div>
        </div>
    </form>

    <div class="card card-pad stack">
        <h2>Einlesen</h2>
        <dl class="kv">
            <dt>Artikel</dt><dd>{{ $lastItems ? 'zuletzt '.$lastItems->finished_at->timezone('Europe/Berlin')->format('d.m.Y H:i') : 'noch nicht eingelesen' }}</dd>
            <dt>Aufträge</dt><dd>{{ $lastOrders ? 'zuletzt '.$lastOrders->finished_at->timezone('Europe/Berlin')->format('d.m.Y H:i') : 'noch nicht eingelesen' }}</dd>
        </dl>
        <div class="row">
            <button class="btn btn-primary" wire:click="start('items', true)" @disabled(! $configured)>{{ $lastItems ? 'Alle Artikel neu einlesen' : 'Artikel einlesen' }}</button>
            @if ($lastItems)<button class="btn" wire:click="start('items')">Nur Änderungen</button>@endif
            <button class="btn" wire:click="start('orders')" @disabled(! $configured)>Aufträge einlesen</button>
        </div>
        <p class="muted small" style="margin:0">Danach laufen Artikel und Aufträge automatisch mit (Zeitplan wie Shopify bzw. Verkäufe). Aufträge ohne Kundendaten. Herkunft „Mandant“ zählt zum Kanal Onlineshop, andere Herkünfte ordnest du unter Preisregeln über die Plattformnamen zu.</p>
    </div>
</x-settings-shell>
