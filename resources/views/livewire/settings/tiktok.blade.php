<x-settings-shell active="settings.tiktok" title="TikTok Shop" intro="Angebote lesen und Preise übertragen. Neue Produkte und den Bestand pflegst du vorerst im TikTok Seller Center bzw. über Billbee.">
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
            @if ($connection->isConnected())
                <dl class="kv">
                    <dt>Shop</dt><dd><strong>{{ $connection->account_name ?? 'verbunden' }}</strong></dd>
                    <dt>Verbunden seit</dt><dd>{{ $connection->connected_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</dd>
                    <dt>Angebote erkannt</dt><dd>{{ $listingCount }} SKUs aktiv</dd>
                </dl>
                <div class="row">
                    @if ($appConfigured)<a class="btn" href="{{ route('settings.tiktok.connect') }}">Neu verbinden</a>@endif
                    <button class="btn" wire:click="disconnect" wire:confirm="Verbindung zu TikTok Shop trennen?">Trennen</button>
                </div>
            @elseif (! $appConfigured)
                <div class="notice notice-warn small">
                    Für diese Instanz ist noch keine TikTok-Shop-App hinterlegt. Bitte <span class="mono">TIKTOK_APP_KEY</span>, <span class="mono">TIKTOK_APP_SECRET</span> und, falls vorhanden, <span class="mono">TIKTOK_SERVICE_ID</span> setzen.
                    Als Weiterleitungs-URL der App eintragen: <span class="mono" style="user-select:all">{{ $callbackUrl }}</span>
                </div>
            @else
                <p style="margin:0">Mit „Mit TikTok Shop verbinden“ meldest du dich im Seller Center an und erlaubst Marktschreier den Zugriff.</p>
                <div><a class="btn btn-primary" href="{{ route('settings.tiktok.connect') }}">Mit TikTok Shop verbinden</a></div>
            @endif
        </div>
    </div>
    <p class="muted small">Verkäufe aus TikTok Shop kommen über Billbee herein, wenn der Shop dort angebunden ist. Den Plattformnamen trägst du unter Preisregeln beim Kanal TikTok Shop ein.</p>
</x-settings-shell>
