<x-settings-shell active="settings.shopify" title="Shopify" intro="Artikel, Texte, Varianten und Preise. Nach dem ersten Import ist Marktschreier führend, Änderungen gehen erst nach Freigabe zu Shopify.">
    @if ($notice)
        <div class="notice notice-ok row" role="status" style="margin-bottom:16px"><span>{{ $notice }}</span>
            <button class="linkbtn" style="margin-left:auto" wire:click="$set('notice', null)">Ausblenden</button></div>
    @endif
    <form class="card" wire:submit="save">
        <div class="card-head"><h2>Zugang</h2>
            @if ($lastImport)<span class="muted small">letzter Import {{ $lastImport->finished_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</span>@endif
        </div>
        <div class="card-pad grid-2">
            <label class="field">Shop-Adresse <span class="hint">die myshopify-Adresse, nicht die eigene Domain</span>
                <input class="input" wire:model="shopifyShop" placeholder="johtomarket.myshopify.com" autocomplete="off">
                @error('shopifyShop') <span class="error">{{ $message }}</span> @enderror
            </label>
            <label class="field">API-Version
                <input class="input mono" wire:model="shopifyVersion">
                @error('shopifyVersion') <span class="error">{{ $message }}</span> @enderror
            </label>
            <label class="field">Client-ID <span class="hint">aus dem Shopify Dev Dashboard</span>
                <input class="input mono" wire:model="shopifyClientId" autocomplete="off">
            </label>
            <label class="field">Client-Secret <span class="hint">{{ $hasSecret ? 'gespeichert, leer lassen zum Behalten' : 'noch nicht hinterlegt' }}</span>
                <input class="input mono" type="password" wire:model="shopifyClientSecret" placeholder="{{ $hasSecret ? '••••••••' : '' }}" autocomplete="new-password">
            </label>
            <details class="span-2 small">
                <summary class="muted">Ältere App mit festem Zugriffstoken (shpat_…)</summary>
                <div class="row" style="margin-top:8px">
                    <input class="input mono" style="max-width:420px" type="password" wire:model="shopifyToken" placeholder="{{ $hasToken ? 'gespeichert ••••••••' : 'shpat_…' }}" autocomplete="new-password" aria-label="Festes Zugriffstoken">
                    @if ($hasToken)<button type="button" class="btn btn-sm" wire:click="forgetToken">Token entfernen</button>@endif
                </div>
                <p class="muted">Nur für Apps, die vor 2026 direkt im Shopify-Admin angelegt wurden. Ist ein Token hinterlegt, hat es Vorrang.</p>
            </details>
            <div class="span-2 row">
                <button class="btn btn-primary" type="submit">Speichern</button>
                <button type="button" class="btn" wire:click="testConnection">Verbindung testen <span class="spin" wire:loading wire:target="testConnection"></span></button>
                @if ($test)<span @class(['badge', 'badge-ok' => $test['ok'], 'badge-bad' => ! $test['ok']]) style="white-space:normal">{{ $test['text'] }}</span>@endif
            </div>
        </div>
    </form>
    <div class="card card-pad small">
        <h3 style="margin-bottom:6px">So richtest du den Zugang ein</h3>
        <ol style="margin:0;padding-left:18px;line-height:1.6">
            <li>Im Shopify Dev Dashboard mit dem Konto des Shops eine App anlegen.</li>
            <li>Berechtigungen <span class="mono">read_products, write_products, read_inventory</span> vergeben, Version veröffentlichen, App im Shop installieren.</li>
            <li>Client-ID und Client-Secret hier eintragen, speichern, Verbindung testen. Das Zugriffstoken holt Marktschreier selbst.</li>
        </ol>
    </div>
</x-settings-shell>
