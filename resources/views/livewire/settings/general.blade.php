<x-settings-shell active="settings.general" title="Allgemein">
    @if ($notice)
        <div class="notice notice-ok row" role="status" style="margin-bottom:16px"><span>{{ $notice }}</span>
            <button class="linkbtn" style="margin-left:auto" wire:click="$set('notice', null)">Ausblenden</button></div>
    @endif
    <form class="card" wire:submit="save">
        <div class="card-pad grid-2">
            <label class="field">Firmenname <span class="hint">erscheint unter dem Logo</span>
                <input class="input" wire:model="company" placeholder="z. B. Johto Market">
                @error('company') <span class="error">{{ $message }}</span> @enderror
            </label>
            <div></div>
            <label class="field">Mehrwertsteuer in % <span class="hint">für Netto, Marge und Gewinn</span>
                <input class="input num" style="max-width:140px" wire:model="vatRate" inputmode="decimal">
                @error('vatRate') <span class="error">{{ $message }}</span> @enderror
            </label>
            <label class="field">Mindestgewinn je Verkauf in € <span class="hint">darunter meldet der Chancenfinder „zu wenig Gewinn“</span>
                <input class="input num" style="max-width:140px" wire:model="minProfit" inputmode="decimal">
                @error('minProfit') <span class="error">{{ $message }}</span> @enderror
            </label>
            <h3 class="span-2" style="margin:8px 0 0">KI-Textvorschläge</h3>
            <label class="field">Anbieter <span class="hint">wird für alle Vorschläge genutzt</span>
                <select class="input" wire:model.live="aiProvider">
                    @foreach ($providers as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select>
                @error('aiProvider') <span class="error">{{ $message }}</span> @enderror
            </label>
            <div></div>
            @if ($aiProvider === 'openai')
                <label class="field">OpenAI-API-Schlüssel <span class="hint">{{ $hasOpenAiKey ? 'gespeichert, leer lassen zum Behalten' : 'von platform.openai.com' }}</span>
                    <input class="input mono" type="password" wire:model="openAiKey" placeholder="{{ $hasOpenAiKey ? '••••••••' : 'sk-…' }}" autocomplete="new-password">
                </label>
                <label class="field">Modell
                    <input class="input mono" wire:model="openAiModel" placeholder="gpt-6-luna">
                    @error('openAiModel') <span class="error">{{ $message }}</span> @enderror
                </label>
            @else
                <label class="field">Claude-API-Schlüssel <span class="hint">{{ $hasAiKey ? 'gespeichert, leer lassen zum Behalten' : 'von console.anthropic.com' }}</span>
                    <input class="input mono" type="password" wire:model="aiKey" placeholder="{{ $hasAiKey ? '••••••••' : 'sk-ant-…' }}" autocomplete="new-password">
                </label>
                <label class="field">Modell
                    <input class="input mono" wire:model="aiModel" placeholder="claude-sonnet-5-5">
                    @error('aiModel') <span class="error">{{ $message }}</span> @enderror
                </label>
            @endif
            <p class="small muted span-2" style="margin:0">Für Vorschläge werden nur Titel, Beschreibung, Marke, Merkmale und Varianten des Artikels an den gewählten Anbieter geschickt, keine Kunden- oder Bestelldaten. Ohne Schlüssel bleiben die Textfelder manuell.</p>
            <label class="check span-2"><input type="checkbox" wire:model="require2fa"> Zwei-Faktor-Anmeldung für Administratoren vorschreiben</label>
            <div><button class="btn btn-primary" type="submit">Speichern</button></div>
        </div>
    </form>
</x-settings-shell>
