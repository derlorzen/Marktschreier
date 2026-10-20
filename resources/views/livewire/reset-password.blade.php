<div class="card card-pad">
    <div class="brand" style="padding:0 0 14px"><span class="brand-mark" aria-hidden="true">M</span> Marktschreier</div>
    <h1 style="font-size:18px;margin-bottom:10px">Neues Passwort</h1>
    <form wire:submit="save" class="stack">
        <label class="field">E-Mail
            <input class="input" type="email" wire:model="email" autocomplete="username" required>
            @error('email') <span class="error">{{ $message }}</span> @enderror
        </label>
        <label class="field">Neues Passwort <span class="hint">mindestens 10 Zeichen</span>
            <input class="input" type="password" wire:model="password" autocomplete="new-password" required>
            @error('password') <span class="error">{{ $message }}</span> @enderror
        </label>
        <label class="field">Wiederholen
            <input class="input" type="password" wire:model="password_confirmation" autocomplete="new-password" required>
        </label>
        <button class="btn btn-primary" type="submit" style="width:100%">Passwort speichern</button>
    </form>
</div>
