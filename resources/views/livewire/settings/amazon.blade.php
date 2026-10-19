<x-settings-shell active="settings.amazon" title="Amazon" intro="Angebote lesen, Preise übertragen und Angebote zu vorhandenen Produkten (per GTIN) anlegen. Den Bestand pflegt bei Händlerversand weiterhin Billbee, bei FBA Amazon.">
    @if ($notice)
        <div class="notice notice-ok row" role="status" style="margin-bottom:16px"><span>{{ $notice }}</span>
            <button class="linkbtn" style="margin-left:auto" wire:click="$set('notice', null)">Ausblenden</button></div>
    @endif
    @if ($error || $connection->last_error)
        <div class="notice notice-bad" style="margin-bottom:16px">{{ $error ?? $connection->last_error }}</div>
    @endif

    <div class="card">
        <div class="card-head"><h2>Verbindung</h2><span class="muted small">amazon.de</span></div>
        <div class="card-pad stack">
            @if (! $appConfigured && ! $connection->isConnected())
                <div class="notice notice-warn small">
                    Für diese Instanz ist noch keine Amazon-Entwickler-App hinterlegt. Bitte <span class="mono">AMAZON_LWA_CLIENT_ID</span>, <span class="mono">AMAZON_LWA_CLIENT_SECRET</span> und <span class="mono">AMAZON_APP_ID</span> setzen.
                    Als Weiterleitungs-URI der App eintragen: <span class="mono" style="user-select:all">{{ $callbackUrl }}</span>
                </div>
            @elseif ($connection->isConnected())
                <dl class="kv">
                    <dt>Konto</dt><dd><strong>{{ $connection->account_name ?? 'verbunden' }}</strong></dd>
                    <dt>Verbunden seit</dt><dd>{{ $connection->connected_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</dd>
                    <dt>Angebote erkannt</dt><dd>{{ $listingCount }} SKUs kaufbar @if ($connection->listings_checked_at)<span class="muted">(Stand {{ $connection->listings_checked_at->timezone('Europe/Berlin')->format('d.m. H:i') }})</span>@endif</dd>
                </dl>
                <div class="row">
                    <button class="btn" wire:click="test">Verbindung testen <span class="spin" wire:loading wire:target="test"></span></button>
                    @if ($websiteAuth)<a class="btn" href="{{ route('settings.amazon.connect') }}">Neu verbinden</a>@endif
                    <button class="btn" wire:click="disconnect" wire:confirm="Verbindung zu Amazon trennen?">Trennen</button>
                </div>
            @else
                @if ($websiteAuth)
                    <p style="margin:0">Mit „Mit Amazon verbinden“ meldest du dich in Seller Central an und erlaubst Marktschreier den Zugriff.</p>
                    <div><a class="btn btn-primary" href="{{ route('settings.amazon.connect') }}">Mit Amazon verbinden</a></div>
                @endif
                <details @if (! $websiteAuth) open @endif>
                    <summary class="muted small">Eigene App selbst autorisiert? Refresh-Token einfügen</summary>
                    <form class="grid-2" style="margin-top:10px" wire:submit="connectManually">
                        <label class="field">Verkäufer-ID <span class="hint">Seller Central, Einstellungen, Kontoinformationen</span>
                            <input class="input mono" wire:model="manualSellerId" placeholder="A2EXAMPLE1234" autocomplete="off">
                            @error('manualSellerId') <span class="error">{{ $message }}</span> @enderror
                        </label>
                        <label class="field">Refresh-Token
                            <input class="input mono" type="password" wire:model="manualRefreshToken" placeholder="Atzr|…" autocomplete="new-password">
                            @error('manualRefreshToken') <span class="error">{{ $message }}</span> @enderror
                        </label>
                        <div><button class="btn btn-primary" type="submit">Verbinden <span class="spin" wire:loading wire:target="connectManually"></span></button></div>
                    </form>
                </details>
            @endif
        </div>
    </div>

    <form class="card" wire:submit="save">
        <div class="card-head"><h2>Vorgaben für neue Angebote</h2></div>
        <div class="card-pad stack">
            <label class="field" style="max-width:420px">Versand
                <select class="input" wire:model="fulfillment">
                    <option value="FBM">Versand durch Verkäufer, Startbestand aus Billbee</option>
                    <option value="FBA">Versand durch Amazon (FBA)</option>
                </select>
            </label>
            <div><button class="btn btn-primary" type="submit">Speichern</button></div>
        </div>
    </form>
</x-settings-shell>
