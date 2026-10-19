<div class="card card-pad">
    <div class="brand" style="padding:0 0 18px"><span class="brand-mark" aria-hidden="true">M</span> Marktschreier</div>
    @if (session('flash'))<div class="notice notice-bad small" style="margin-bottom:12px">{{ session('flash') }}</div>@endif
    <form wire:submit="login" class="stack">
        <label class="field">E-Mail
            <input class="input" type="email" wire:model="email" autocomplete="username" autofocus required>
            @error('email') <span class="error">{{ $message }}</span> @enderror
        </label>
        <label class="field">Passwort
            <input class="input" type="password" wire:model="password" autocomplete="current-password" required>
            @error('password') <span class="error">{{ $message }}</span> @enderror
        </label>
        <label class="check"><input type="checkbox" wire:model="remember"> Angemeldet bleiben</label>
        <button class="btn btn-primary" type="submit" style="width:100%">Anmelden <span class="spin" wire:loading wire:target="login"></span></button>
    </form>
</div>
