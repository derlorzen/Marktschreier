<x-settings-shell active="settings" title="Einstellungen" intro="Alle Verbindungen und Vorgaben dieser Instanz auf einen Blick.">
    <div class="status-grid">
        @foreach ($tiles as $t)
            <a class="status-tile" href="{{ route($t['route']) }}" wire:navigate>
                <h3><span @class(['dot', 'dot-ok' => $t['state'] === 'ok', 'dot-warn' => $t['state'] === 'warn', 'dot-bad' => $t['state'] === 'bad']) aria-hidden="true"></span>{{ $t['title'] }}</h3>
                <span class="small muted">{{ $t['text'] }}</span>
                <span class="sr-only">Status: {{ ['ok' => 'in Ordnung', 'warn' => 'unvollständig', 'bad' => 'Fehler', 'off' => 'nicht eingerichtet'][$t['state']] }}</span>
            </a>
        @endforeach
    </div>
    <p class="muted small" style="margin-top:16px">Gebühren und Versandkosten je Kanal pflegst du unter <a href="{{ route('pricing') }}" wire:navigate>Preisregeln</a>.</p>
</x-settings-shell>
