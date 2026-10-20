<x-settings-shell active="settings.account" title="Mein Konto">
    @if ($user->must_change_password)
        <div class="notice notice-warn" style="margin-bottom:16px"><strong>Bitte zuerst ein eigenes Passwort festlegen.</strong> Du bist mit einem Startpasswort angemeldet.</div>
    @endif
    @if ($notice)
        <div class="notice notice-ok row" role="status" style="margin-bottom:16px"><span>{{ $notice }}</span>
            <button class="linkbtn" style="margin-left:auto" wire:click="$set('notice', null)">Ausblenden</button></div>
    @endif

    <form class="card" wire:submit="saveProfile">
        <div class="card-head"><h2>Profil</h2><span class="muted small">{{ $user->roleLabel() }}</span></div>
        <div class="card-pad grid-2">
            <label class="field">Name
                <input class="input" wire:model="name" autocomplete="name">
                @error('name') <span class="error">{{ $message }}</span> @enderror
            </label>
            <label class="field">E-Mail <span class="hint">zum Anmelden</span>
                <input class="input" type="email" wire:model="email" autocomplete="email">
                @error('email') <span class="error">{{ $message }}</span> @enderror
            </label>
            <div><button class="btn btn-primary" type="submit">Speichern</button></div>
        </div>
    </form>

    <form class="card" wire:submit="saveMailPrefs">
        <div class="card-head"><h2>Benachrichtigungen per Mail</h2><span class="muted small">an {{ $user->email }}</span></div>
        <div class="card-pad stack">
            <label class="check"><input type="checkbox" wire:model="mailAlerts"> Sofort bei Fehlern (Abgleich fehlgeschlagen, Verbindung gestört, ausverkauft)</label>
            <label class="check"><input type="checkbox" wire:model="mailWeekly"> Wochenrückblick am Montagmorgen</label>
            <label class="check"><input type="checkbox" wire:model="mailMonthly"> Monatsabschluss als PDF am 1. des Monats</label>
            <div><button class="btn btn-primary" type="submit">Speichern</button></div>
        </div>
    </form>

    <form class="card" wire:submit="changePassword">
        <div class="card-head"><h2>Passwort ändern</h2></div>
        <div class="card-pad grid-2">
            <label class="field span-2" style="max-width:420px">Bisheriges Passwort
                <input class="input" type="password" wire:model="currentPassword" autocomplete="current-password">
                @error('currentPassword') <span class="error">{{ $message }}</span> @enderror
            </label>
            <label class="field">Neues Passwort <span class="hint">mindestens 10 Zeichen</span>
                <input class="input" type="password" wire:model="newPassword" autocomplete="new-password">
                @error('newPassword') <span class="error">{{ $message }}</span> @enderror
            </label>
            <label class="field">Neues Passwort wiederholen
                <input class="input" type="password" wire:model="newPassword_confirmation" autocomplete="new-password">
            </label>
            <div><button class="btn btn-primary" type="submit">Passwort ändern</button></div>
        </div>
    </form>

    <div class="card">
        <div class="card-head"><h2>Zwei-Faktor-Anmeldung</h2>
            @if ($user->hasTwoFactor())<span class="badge badge-ok">eingeschaltet</span>@else<span class="badge">aus</span>@endif
        </div>
        <div class="card-pad stack">
            @if ($recoveryCodes)
                <div class="notice notice-warn">
                    <strong>Wiederherstellungscodes</strong>, jeder funktioniert einmal, falls das Handy fehlt. Jetzt notieren, sie werden nicht wieder angezeigt.
                    <div class="mono" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:6px;margin-top:8px;user-select:all">
                        @foreach ($recoveryCodes as $c)<span>{{ $c }}</span>@endforeach
                    </div>
                    <button class="btn btn-sm" style="margin-top:8px" wire:click="$set('recoveryCodes', null)">Notiert</button>
                </div>
            @endif
            @if ($user->hasTwoFactor())
                <p style="margin:0">Bei der Anmeldung fragt Marktschreier nach dem Code aus deiner Authenticator-App.</p>
                @unless ($twoFactorRequired)
                    <form class="row" wire:submit="disableTwoFactor">
                        <input class="input" style="max-width:260px" type="password" wire:model="twoFactorPassword" placeholder="Passwort zur Bestätigung" autocomplete="current-password" aria-label="Passwort">
                        <button class="btn" type="submit">Ausschalten</button>
                        @error('twoFactorPassword') <span class="error">{{ $message }}</span> @enderror
                    </form>
                @else
                    <p class="muted small" style="margin:0">Für Administratoren vorgeschrieben.</p>
                @endunless
            @elseif ($qr)
                <div class="row" style="align-items:flex-start;gap:20px">
                    <div style="background:#fff;padding:8px;border-radius:8px">{!! $qr !!}</div>
                    <div class="stack" style="flex:1;min-width:240px">
                        <p style="margin:0">Den QR-Code mit einer Authenticator-App scannen (z. B. Google Authenticator, Microsoft Authenticator, 1Password) und den angezeigten Code eingeben.</p>
                        <p class="small muted" style="margin:0">Kein Scanner? Schlüssel von Hand eingeben: <span class="mono" style="user-select:all">{{ $secretText }}</span></p>
                        <form class="row" wire:submit="confirmTwoFactor">
                            <input class="input mono" style="max-width:160px" wire:model="twoFactorCode" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" aria-label="Code aus der App">
                            <button class="btn btn-primary" type="submit">Bestätigen</button>
                            <button class="btn" type="button" wire:click="$set('pendingSecret', null)">Abbrechen</button>
                        </form>
                        @error('twoFactorCode') <span class="error">{{ $message }}</span> @enderror
                    </div>
                </div>
            @else
                @if ($twoFactorRequired)<div class="notice notice-warn"><strong>Für Administratoren vorgeschrieben.</strong> Bitte jetzt einrichten, vorher sind die anderen Seiten gesperrt.</div>@endif
                <p style="margin:0">Schützt den Zugang zusätzlich mit einem Code vom Handy. Empfohlen für alle, die Einstellungen und Verbindungen verwalten.</p>
                <div><button class="btn btn-primary" wire:click="startTwoFactor">Einrichten</button></div>
            @endif
        </div>
    </div>
</x-settings-shell>
