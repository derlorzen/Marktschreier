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
</x-settings-shell>
