<x-settings-shell active="settings.backups" title="Sicherung" intro="Die Datenbank wird täglich gesichert und 14 Tage aufbewahrt. Zusätzlich empfiehlt sich eine Kopie außerhalb des Servers, z. B. über die Backup-Funktion von Coolify.">
    @if ($notice)
        <div class="notice notice-ok row" role="status" style="margin-bottom:16px"><span>{{ $notice }}</span>
            <button class="linkbtn" style="margin-left:auto" wire:click="$set('notice', null)">Ausblenden</button></div>
    @endif
    @if ($stale)
        <div class="notice notice-warn" style="margin-bottom:16px">Seit mehr als 36 Stunden gibt es keine neue Sicherung. Läuft der Dienst „backup“?</div>
    @endif
    <div class="card">
        <div class="card-head"><h2>Vorhandene Sicherungen</h2><span style="flex:1"></span>
            <button class="btn btn-sm btn-primary" wire:click="backupNow">Jetzt sichern <span class="spin" wire:loading wire:target="backupNow"></span></button></div>
        <div class="table-wrap">
            <table class="list">
                <thead><tr><th>Datei</th><th>Erstellt</th><th class="num">Größe</th><th></th></tr></thead>
                <tbody>
                @forelse ($backups as $b)
                    <tr>
                        <td class="mono small">{{ $b['name'] }}</td>
                        <td>{{ \Illuminate\Support\Carbon::createFromTimestamp($b['time'])->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</td>
                        <td class="num">{{ number_format($b['size'] / 1024 / 1024, 1, ',', '.') }} MB</td>
                        <td style="text-align:right"><a class="btn btn-sm" href="{{ route('settings.backups.download', $b['name']) }}">Herunterladen</a></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty">Noch keine Sicherung vorhanden.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="card card-pad small">
        <h3 style="margin-bottom:6px">Zurückspielen</h3>
        <p style="margin:0">Auf dem Server: <span class="mono" style="user-select:all">gunzip -c DATEI | docker compose exec -T postgres psql -U marktschreier marktschreier</span>. Vorher die App anhalten. Ausführlich in der README.</p>
        @if ($last)<p class="muted" style="margin:8px 0 0">Letzter Lauf: {{ $last }}</p>@endif
    </div>
</x-settings-shell>
