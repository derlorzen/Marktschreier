<div>
    <div class="page-head"><h1>Einstellungen</h1></div>

    @if ($notice)
        <div class="notice notice-ok row" role="status" style="margin-bottom:14px"><span>{{ $notice }}</span>
            <button class="linkbtn" style="margin-left:auto" wire:click="$set('notice', null)">Ausblenden</button></div>
    @endif

    <form wire:submit="save">
        <div class="card">
            <div class="card-head"><h2>Shopify</h2></div>
            <div class="card-pad grid-2">
                <label class="field">Shop-Adresse <span class="hint">die myshopify-Adresse, nicht die eigene Domain</span>
                    <input class="input" wire:model="shopifyShop" placeholder="johtomarket.myshopify.com" autocomplete="off">
                    @error('shopifyShop') <span class="error">{{ $message }}</span> @enderror
                </label>
                <div></div>
                <label class="field">Client-ID <span class="hint">aus dem Shopify Dev Dashboard</span>
                    <input class="input mono" wire:model="shopifyClientId" autocomplete="off">
                </label>
                <label class="field">Client-Secret <span class="hint">{{ $hasShopifySecret ? 'gespeichert, leer lassen zum Behalten' : 'noch nicht hinterlegt' }}</span>
                    <input class="input mono" type="password" wire:model="shopifyClientSecret" placeholder="{{ $hasShopifySecret ? '••••••••' : '' }}" autocomplete="new-password">
                </label>
                <details class="span-2 small">
                    <summary class="muted">Ältere App mit festem Zugriffstoken (shpat_…)</summary>
                    <div class="row" style="margin-top:8px">
                        <input class="input mono" style="max-width:420px" type="password" wire:model="shopifyToken" placeholder="{{ $hasShopifyToken ? 'gespeichert ••••••••' : 'shpat_…' }}" autocomplete="new-password" aria-label="Festes Zugriffstoken">
                        @if ($hasShopifyToken)<button type="button" class="btn btn-sm" wire:click="forgetShopifyToken">Token entfernen</button>@endif
                    </div>
                    <p class="muted">Nur für Apps, die vor 2026 direkt im Shopify-Admin angelegt wurden. Ist ein Token hinterlegt, hat es Vorrang.</p>
                </details>
                <label class="field">API-Version
                    <input class="input mono" wire:model="shopifyVersion">
                    @error('shopifyVersion') <span class="error">{{ $message }}</span> @enderror
                </label>
                <div class="small muted" style="align-self:end">
                    Im Shopify Dev Dashboard mit dem Konto des Shops eine App anlegen, Berechtigungen <span class="mono">read_products, write_products, read_inventory</span> vergeben, im Shop installieren. Marktschreier holt das Zugriffstoken dann selbst.
                </div>
                <div class="span-2 row">
                    <button type="button" class="btn" wire:click="testShopify">Verbindung testen <span class="spin" wire:loading wire:target="testShopify"></span></button>
                    @isset($tests['shopify'])
                        <span @class(['badge', 'badge-ok' => $tests['shopify']['ok'], 'badge-bad' => ! $tests['shopify']['ok']]) style="white-space:normal">{{ $tests['shopify']['text'] }}</span>
                    @endisset
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>Billbee</h2><span class="muted small">nur lesend: Einkaufspreis und Bestand</span></div>
            <div class="card-pad grid-2">
                <label class="field">API-Key <span class="hint">{{ $hasBillbeeKey ? 'gespeichert, leer lassen zum Behalten' : 'bei Billbee anzufragen' }}</span>
                    <input class="input mono" type="password" wire:model="billbeeApiKey" placeholder="{{ $hasBillbeeKey ? '••••••••' : '' }}" autocomplete="new-password">
                </label>
                <label class="field">Benutzer <span class="hint">E-Mail des Billbee-Kontos</span>
                    <input class="input" wire:model="billbeeUser" autocomplete="off">
                </label>
                <label class="field">API-Passwort <span class="hint">{{ $hasBillbeePassword ? 'gespeichert, leer lassen zum Behalten' : 'in Billbee unter Einstellungen, API festlegen' }}</span>
                    <input class="input mono" type="password" wire:model="billbeePassword" placeholder="{{ $hasBillbeePassword ? '••••••••' : '' }}" autocomplete="new-password">
                </label>
                <label class="field">Einkaufspreis in Billbee ist gepflegt als
                    <select class="input" wire:model="costMode">
                        <option value="net">netto</option>
                        <option value="gross">brutto (wird in netto umgerechnet)</option>
                    </select>
                </label>
                <label class="field">Standard-MwSt. in % <span class="hint">für Marge und Umrechnung, falls Billbee keinen Satz liefert</span>
                    <input class="input" wire:model="vatRate" inputmode="decimal">
                    @error('vatRate') <span class="error">{{ $message }}</span> @enderror
                </label>
                <div class="span-2 row">
                    <button type="button" class="btn" wire:click="testBillbee">Verbindung testen <span class="spin" wire:loading wire:target="testBillbee"></span></button>
                    @isset($tests['billbee'])
                        <span @class(['badge', 'badge-ok' => $tests['billbee']['ok'], 'badge-bad' => ! $tests['billbee']['ok']]) style="white-space:normal">{{ $tests['billbee']['text'] }}</span>
                    @endisset
                </div>
            </div>
        </div>

        <div class="card card-pad">
            <p class="muted small" style="margin-top:0">Automatischer Abgleich: Billbee alle {{ $intervals['billbee_interval_minutes'] }} Minuten, Änderungen aus Shopify alle {{ $intervals['shopify_interval_minutes'] }} Minuten (nach dem ersten Import). Zugangsdaten werden verschlüsselt gespeichert. Erst speichern, dann testen.</p>
            <button class="btn btn-primary" type="submit">Speichern <span class="spin" wire:loading wire:target="save"></span></button>
        </div>
    </form>
</div>
