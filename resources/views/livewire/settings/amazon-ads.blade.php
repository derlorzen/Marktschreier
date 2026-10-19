<x-settings-shell active="settings.ads" title="Amazon Werbung" intro="Kampagnen, Suchbegriffe und Vorschläge für Sponsored Products. Marktschreier ändert Gebote, Budgets und Kampagnen nur auf deinen Klick.">
    @if ($notice)<div class="notice notice-ok row" role="status" style="margin-bottom:16px"><span>{{ $notice }}</span><button class="linkbtn" style="margin-left:auto" wire:click="$set('notice', null)">Ausblenden</button></div>@endif
    @if ($error || $connection->last_error)<div class="notice notice-bad" style="margin-bottom:16px">{{ $error ?? $connection->last_error }}</div>@endif

    <div class="card">
        <div class="card-head"><h2>Verbindung</h2></div>
        <div class="card-pad stack">
            @if ($connection->isConnected())
                <dl class="kv">
                    <dt>Werbekonto</dt><dd><strong>{{ $connection->account_name ?? 'noch nicht gewählt' }}</strong></dd>
                    <dt>Verbunden seit</dt><dd>{{ $connection->connected_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</dd>
                </dl>
                <div class="row">
                    <a class="btn" href="{{ route('settings.ads.connect') }}">Neu verbinden</a>
                    <button class="btn" wire:click="disconnect" wire:confirm="Verbindung zu Amazon Werbung trennen?">Trennen</button>
                </div>
            @elseif (! $appConfigured)
                <div class="notice notice-warn small">Es fehlt die Freigabe für die Amazon Advertising API. Bitte <span class="mono">AMAZON_ADS_CLIENT_ID</span> und <span class="mono">AMAZON_ADS_CLIENT_SECRET</span> setzen (LWA-Sicherheitsprofil mit Ads-API-Zugang). Als erlaubte Rücksprung-Adresse eintragen: <span class="mono" style="user-select:all">{{ $callbackUrl }}</span></div>
            @else
                <p style="margin:0">Mit dem Amazon-Konto anmelden, das Zugriff auf die Werbekonsole hat.</p>
                <div><a class="btn btn-primary" href="{{ route('settings.ads.connect') }}">Mit Amazon Werbung verbinden</a></div>
            @endif
        </div>
    </div>

    <form class="card" wire:submit="save">
        <div class="card-head"><h2>Vorgaben</h2></div>
        <div class="card-pad grid-2">
            <label class="field">Werbekonto (Profil)
                <div class="row">
                    <select class="input" style="flex:1" wire:model="profileId">
                        <option value="">Bitte wählen</option>
                        @foreach ($profiles as $p)<option value="{{ $p['id'] }}">{{ $p['name'] }} · {{ $p['country'] }}</option>@endforeach
                    </select>
                    <button type="button" class="btn btn-sm" wire:click="loadProfiles" @disabled(! $connection->isConnected())>Laden</button>
                </div>
            </label>
            <label class="field">Ziel-ACoS in % <span class="hint">Werbekosten vom Werbeumsatz, die noch in Ordnung sind</span>
                <input class="input num" style="max-width:140px" wire:model="targetAcos" inputmode="decimal">
                @error('targetAcos') <span class="error">{{ $message }}</span> @enderror
            </label>
            <div><button class="btn btn-primary" type="submit">Speichern</button></div>
        </div>
    </form>
</x-settings-shell>
