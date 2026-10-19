<x-settings-shell active="settings.users" title="Benutzer" intro="Administratoren dürfen Einstellungen, Verbindungen und Benutzer verwalten. Mitarbeiter pflegen Artikel, Preise und Chancen.">
    @if ($issuedPassword)
        <div class="notice notice-warn" style="margin-bottom:16px" role="status">
            <strong>Startpasswort für {{ $issuedPassword['name'] }}</strong> ({{ $issuedPassword['email'] }}):
            <code style="font-size:16px;padding:2px 8px;background:var(--surface);border-radius:6px;user-select:all">{{ $issuedPassword['password'] }}</code>
            <div class="small" style="margin-top:6px">Wird nur jetzt angezeigt. Bitte sicher weitergeben, beim ersten Anmelden muss ein eigenes Passwort gesetzt werden.</div>
            <button class="btn btn-sm" style="margin-top:8px" wire:click="$set('issuedPassword', null)">Notiert, ausblenden</button>
        </div>
    @endif
    @if ($notice)
        <div class="notice notice-ok row" role="status" style="margin-bottom:16px"><span>{{ $notice }}</span>
            <button class="linkbtn" style="margin-left:auto" wire:click="$set('notice', null)">Ausblenden</button></div>
    @endif

    <div class="card">
        <div class="card-head">
            <h2>Zugänge</h2><span class="muted small">{{ $users->where('active', true)->count() }} aktiv</span>
            <span style="flex:1"></span>
            @unless ($showForm)<button class="btn btn-sm btn-primary" wire:click="$set('showForm', true)">Benutzer hinzufügen</button>@endunless
        </div>
        @if ($showForm)
            <form class="card-pad grid-3" wire:submit="create" style="border-bottom:1px solid var(--line);background:var(--tint-2)">
                <label class="field">Name
                    <input class="input" wire:model="name" autofocus>
                    @error('name') <span class="error">{{ $message }}</span> @enderror
                </label>
                <label class="field">E-Mail
                    <input class="input" type="email" wire:model="email">
                    @error('email') <span class="error">{{ $message }}</span> @enderror
                </label>
                <label class="field">Rolle
                    <select class="input" wire:model="role">
                        @foreach (\App\Models\User::ROLES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                    </select>
                </label>
                <div class="row" style="grid-column:1/-1">
                    <button class="btn btn-primary" type="submit">Anlegen und Startpasswort erzeugen</button>
                    <button class="btn" type="button" wire:click="$set('showForm', false)">Abbrechen</button>
                </div>
            </form>
        @endif
        <div class="table-wrap">
            <table class="list">
                <thead><tr><th>Name</th><th>Rolle</th><th>Status</th><th>Zuletzt angemeldet</th><th></th></tr></thead>
                <tbody>
                @foreach ($users as $u)
                    <tr wire:key="u{{ $u->id }}" @if (! $u->active) style="opacity:.6" @endif>
                        <td><strong>{{ $u->name }}</strong>@if ($u->id === auth()->id()) <span class="badge">du</span>@endif<div class="small muted">{{ $u->email }}</div></td>
                        <td>
                            <select class="input input-sm" style="width:auto" wire:change="setRole({{ $u->id }}, $event.target.value)" aria-label="Rolle von {{ $u->name }}" @disabled(! $u->active)>
                                @foreach (\App\Models\User::ROLES as $key => $label)<option value="{{ $key }}" @selected($u->role === $key)>{{ $label }}</option>@endforeach
                            </select>
                        </td>
                        <td>
                            @if (! $u->active)<span class="badge badge-bad">gesperrt</span>
                            @elseif ($u->must_change_password)<span class="badge badge-amber">Startpasswort</span>
                            @else<span class="badge badge-ok">aktiv</span>@endif
                        </td>
                        <td class="small">{{ $u->last_login_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') ?? 'noch nie' }}</td>
                        <td class="nowrap" style="text-align:right">
                            @if ($u->id !== auth()->id())
                                <button class="btn btn-sm" wire:click="resetPassword({{ $u->id }})" wire:confirm="Neues Startpasswort für {{ $u->name }} erzeugen? Das bisherige gilt dann nicht mehr.">Neues Passwort</button>
                                <button class="btn btn-sm" wire:click="toggleActive({{ $u->id }})">{{ $u->active ? 'Sperren' : 'Freischalten' }}</button>
                            @else
                                <a class="btn btn-sm" href="{{ route('settings.account') }}" wire:navigate>Mein Konto</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    <p class="muted small">Zugänge werden gesperrt statt gelöscht, damit der Verlauf nachvollziehbar bleibt.</p>
</x-settings-shell>
