<div class="card card-pad">
    <div class="brand" style="padding:0 0 14px"><span class="brand-mark" aria-hidden="true">M</span> Marktschreier</div>
    <h1 style="font-size:18px;margin-bottom:6px">Passwort vergessen</h1>
    @if ($sent)
        <p>Wenn es zu dieser Adresse einen Zugang gibt, ist jetzt eine Mail mit einem Link unterwegs. Der Link gilt 60 Minuten.</p>
        <a class="btn" href="{{ route('login') }}">Zur Anmeldung</a>
    @else
        <p class="muted small" style="margin-top:0">Wir schicken dir einen Link, mit dem du ein neues Passwort setzen kannst.</p>
        <form wire:submit="send" class="stack">
            <label class="field">E-Mail
                <input class="input" type="email" wire:model="email" autocomplete="username" autofocus required>
                @error('email') <span class="error">{{ $message }}</span> @enderror
            </label>
            <button class="btn btn-primary" type="submit" style="width:100%">Link schicken <span class="spin" wire:loading wire:target="send"></span></button>
            <a class="small" href="{{ route('login') }}">Zurück zur Anmeldung</a>
        </form>
    @endif
</div>
