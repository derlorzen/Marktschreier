<div class="card card-pad">
    <div class="brand" style="padding:0 0 14px"><span class="brand-mark" aria-hidden="true">M</span> Marktschreier</div>
    <h1 style="font-size:18px;margin-bottom:6px">Bestätigungscode</h1>
    <p class="muted small" style="margin-top:0">Gib den sechsstelligen Code aus deiner Authenticator-App ein. Ohne Handy geht auch ein Wiederherstellungscode.</p>
    <form wire:submit="verify" class="stack">
        <label class="field">Code
            <input class="input mono" wire:model="code" inputmode="numeric" autocomplete="one-time-code" autofocus required>
            @error('code') <span class="error">{{ $message }}</span> @enderror
        </label>
        <button class="btn btn-primary" type="submit" style="width:100%">Anmelden <span class="spin" wire:loading wire:target="verify"></span></button>
        <a class="small" href="{{ route('login') }}">Zurück</a>
    </form>
</div>
