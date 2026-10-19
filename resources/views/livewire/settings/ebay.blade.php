<x-settings-shell active="settings.ebay" title="eBay" intro="Angebote lesen, Preise übertragen und neue Angebote anlegen. Den Bestand auf eBay pflegt weiterhin Billbee.">
    @if ($notice)
        <div class="notice notice-ok row" role="status" style="margin-bottom:16px"><span>{{ $notice }}</span>
            <button class="linkbtn" style="margin-left:auto" wire:click="$set('notice', null)">Ausblenden</button></div>
    @endif
    @if ($error || $connection->last_error)
        <div class="notice notice-bad" style="margin-bottom:16px">{{ $error ?? $connection->last_error }}</div>
    @endif

    <div class="card">
        <div class="card-head"><h2>Verbindung</h2></div>
        <div class="card-pad stack">
            @if (! $appConfigured && ! $connection->isConnected())
                <div class="notice notice-warn small">
                    Für diese Instanz ist noch keine eBay-Entwickler-App hinterlegt. Bitte <span class="mono">EBAY_CLIENT_ID</span>, <span class="mono">EBAY_CLIENT_SECRET</span> und <span class="mono">EBAY_RUNAME</span> setzen.
                    Im RuName als Rücksprung-Adresse eintragen: <span class="mono" style="user-select:all">{{ $callbackUrl }}</span>
                </div>
            @elseif ($connection->isConnected())
                <dl class="kv">
                    <dt>Konto</dt><dd><strong>{{ $connection->account_name ?? 'verbunden' }}</strong></dd>
                    <dt>Verbunden seit</dt><dd>{{ $connection->connected_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</dd>
                    <dt>Angebote erkannt</dt><dd>{{ $listingCount }} Varianten online @if ($connection->listings_checked_at)<span class="muted">(Stand {{ $connection->listings_checked_at->timezone('Europe/Berlin')->format('d.m. H:i') }})</span>@endif</dd>
                </dl>
                <div class="row">
                    <a class="btn" href="{{ route('settings.ebay.connect') }}">Neu verbinden</a>
                    <button class="btn" wire:click="disconnect" wire:confirm="Verbindung zu eBay trennen?">Trennen</button>
                </div>
            @else
                <p style="margin:0">Mit „Mit eBay verbinden“ meldest du dich bei eBay an und erlaubst Marktschreier den Zugriff auf dein Verkäuferkonto.</p>
                <div><a class="btn btn-primary" href="{{ route('settings.ebay.connect') }}">Mit eBay verbinden</a></div>
            @endif
        </div>
    </div>

    <form class="card" wire:submit="save">
        <div class="card-head"><h2>Vorgaben für neue Angebote</h2></div>
        <div class="card-pad grid-2">
            <div class="span-2 row">
                <button type="button" class="btn btn-sm" wire:click="loadPolicies" @disabled(! $connection->isConnected())>Geschäftsrichtlinien von eBay laden <span class="spin" wire:loading wire:target="loadPolicies"></span></button>
                <span class="muted small">Versand, Zahlung und Rücknahme kommen aus den Geschäftsrichtlinien in deinem eBay-Konto.</span>
            </div>
            @foreach (['fulfillment' => ['fulfillmentPolicy', 'Versand'], 'payment' => ['paymentPolicy', 'Zahlung'], 'return' => ['returnPolicy', 'Rücknahme']] as $type => [$prop, $label])
                <label class="field">{{ $label }}
                    <select class="input" wire:model="{{ $prop }}">
                        <option value="">Bitte wählen</option>
                        @foreach ($policies[$type] ?? [] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                        @if ($this->{$prop} && ! isset($policies[$type][$this->{$prop}]))<option value="{{ $this->{$prop} }}">Gespeichert: {{ $this->{$prop} }}</option>@endif
                    </select>
                </label>
            @endforeach
            <label class="field">Zustand
                <select class="input" wire:model="condition">
                    @foreach ($conditions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                </select>
            </label>
            <label class="field">Postleitzahl des Artikelstandorts
                <input class="input" wire:model="postalCode" inputmode="numeric" maxlength="5">
                @error('postalCode') <span class="error">{{ $message }}</span> @enderror
            </label>
            <label class="field">Ort
                <input class="input" wire:model="location" placeholder="z. B. Hamburg">
            </label>
            <div class="span-2"><button class="btn btn-primary" type="submit">Speichern</button></div>
        </div>
    </form>
</x-settings-shell>
