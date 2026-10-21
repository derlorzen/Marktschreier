@php
    use App\Support\Format;
    $sections = ['einstellungen' => 'Einstellungen', 'merkmale' => 'Merkmale', 'bilder' => 'Bilder', 'maerkte' => 'Preise und Marktplätze'];
    $effective = $variant->effectiveAttributes();
@endphp
<section class="card" style="margin-top:16px" aria-label="Variante {{ $variant->label() }}">
    <div class="card-pad">
        <div class="row" style="margin-bottom:12px">
            <div style="min-width:0">
                <h3 style="margin:0">{{ $variant->label() }}</h3>
                <div class="small muted">
                    Variante {{ $variant->sku ?: 'ohne SKU' }}
                    @if ($variant->plenty_variation_id) · PlentyONE-Variante {{ $variant->plenty_variation_id }}@endif
                </div>
            </div>
            @if ($variant->is_main)
                <span class="badge badge-ok">Hauptvariante</span>
            @else
                <button class="btn btn-sm" wire:click="makeMain" wire:confirm="{{ $variant->label() }} zur Hauptvariante machen?">Zur Hauptvariante machen</button>
            @endif
            @if (! $variant->active)<span class="badge badge-warn">inaktiv</span>@endif
            <span class="spacer" style="flex:1"></span>
            <div class="seg" role="tablist" aria-label="Bereiche der Variante">
                @foreach ($sections as $key => $label)
                    <button class="seg-btn @if ($section === $key) is-active @endif" role="tab" aria-selected="{{ $section === $key ? 'true' : 'false' }}" wire:click="$set('section', '{{ $key }}')">{{ $label }}</button>
                @endforeach
            </div>
        </div>

        @if ($notice)
            <div class="notice notice-ok row" role="status" style="margin-bottom:12px"><span>{{ $notice }}</span>
                <button class="linkbtn" style="margin-left:auto" wire:click="$set('notice', null)">Ausblenden</button></div>
        @endif

        @if ($section === 'einstellungen')
            <form wire:submit="save">
                <div class="grid-3">
                    <label class="field">Variantennummer (SKU)
                        <input class="input mono" wire:model="form.sku">
                        @error('form.sku') <span class="error">{{ $message }}</span> @enderror
                    </label>
                    <label class="field">GTIN/EAN
                        <input class="input mono" wire:model="form.barcode" inputmode="numeric">
                        @error('form.barcode') <span class="error">{{ $message }}</span> @enderror
                    </label>
                    <label class="field">ASIN
                        <input class="input mono" wire:model="form.asin" maxlength="10" placeholder="B0…" style="text-transform:uppercase">
                        @error('form.asin') <span class="error">{{ $message }}</span> @enderror
                    </label>
                    <label class="field">Modell (Herstellernummer)
                        <input class="input" wire:model="form.model">
                        @error('form.model') <span class="error">{{ $message }}</span> @enderror
                    </label>
                    <label class="field">Gewicht in g
                        <input class="input num" wire:model="form.weight_g" inputmode="numeric">
                        @error('form.weight_g') <span class="error">{{ $message }}</span> @enderror
                    </label>
                    <div class="field">Maße L × B × H in mm
                        <div class="row" style="gap:6px;flex-wrap:nowrap">
                            <input class="input num" wire:model="form.length_mm" inputmode="numeric" aria-label="Länge in mm">
                            <input class="input num" wire:model="form.width_mm" inputmode="numeric" aria-label="Breite in mm">
                            <input class="input num" wire:model="form.height_mm" inputmode="numeric" aria-label="Höhe in mm">
                        </div>
                        @error('form.length_mm') <span class="error">{{ $message }}</span> @enderror
                        @error('form.width_mm') <span class="error">{{ $message }}</span> @enderror
                        @error('form.height_mm') <span class="error">{{ $message }}</span> @enderror
                    </div>
                </div>
                <label class="check" style="margin-top:12px"><input type="checkbox" wire:model="form.active"> Aktiv</label>
                <div class="small muted">Inaktive Varianten bekommen keine neuen Angebote auf Marktplätzen.</div>
                <dl class="facts">
                    <dt>EK netto (Billbee)</dt><dd>{{ Format::money($variant->cost_net) }}</dd>
                    <dt>Bestand (Billbee)</dt><dd>{{ $variant->stock === null ? '–' : number_format($variant->stock, 0, ',', '.') }}</dd>
                    <dt>Preis im Shop</dt><dd>{{ Format::money($variant->price) }}</dd>
                </dl>
                <p class="small muted">SKU und GTIN gehen mit „Zu Shopify“ an den Shop. ASIN, Modell, Gewicht und Maße bleiben in Marktschreier und werden für Amazon und eBay genutzt.</p>
                <button class="btn btn-primary" type="submit">Variante speichern <span class="spin" wire:loading wire:target="save"></span></button>
            </form>

        @elseif ($section === 'merkmale')
            <p class="small muted" style="margin-top:0">Wie bei PlentyONE erbt die Variante die Merkmale des Artikels. Trägst du einen eigenen Wert ein, gilt er nur für diese Variante. Leer heißt: Wert vom Artikel.</p>
            <form wire:submit="saveAttributes">
                @if ($attrs === [])
                    <div class="empty" style="padding:20px">Noch keine Merkmale. Am Artikel unter „Merkmale“ oder hier nur für diese Variante anlegen.</div>
                @else
                    <div class="table-wrap">
                        <table class="list">
                            <thead><tr><th>Merkmal</th><th>Vom Artikel</th><th>Eigener Wert</th><th>Gilt</th></tr></thead>
                            <tbody>
                            @foreach ($attrs as $i => $row)
                                @php [$key, $value] = [$row['key'], $row['value']]; @endphp
                                <tr wire:key="va{{ md5($key) }}">
                                    <td class="nowrap"><strong>{{ $key }}</strong></td>
                                    <td class="small muted">{{ $inherited[$key] ?? 'nicht gesetzt' }}</td>
                                    <td style="min-width:200px"><input class="input input-sm" wire:model="attrs.{{ $i }}.value" placeholder="{{ $inherited[$key] ?? '' }}" aria-label="Eigener Wert {{ $key }}"></td>
                                    <td class="small">{{ $effective[$key] ?? '–' }} <span class="muted">{{ filled($value) ? '(eigen)' : '(geerbt)' }}</span></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <button class="btn btn-primary btn-sm" type="submit" style="margin-top:10px">Merkmale speichern <span class="spin" wire:loading wire:target="saveAttributes"></span></button>
                @endif
            </form>
            <form class="row" style="margin-top:14px" wire:submit="addAttribute">
                <input class="input input-sm" style="flex:1 1 160px" wire:model="newAttr.key" placeholder="Merkmal, z. B. Farbe" aria-label="Neues Merkmal">
                <input class="input input-sm" style="flex:2 1 220px" wire:model="newAttr.value" placeholder="Wert nur für diese Variante" aria-label="Wert">
                <button class="btn btn-sm" type="submit">Hinzufügen</button>
            </form>
            @error('newAttr.key') <div class="error">{{ $message }}</div> @enderror
            @error('newAttr.value') <div class="error">{{ $message }}</div> @enderror

        @elseif ($section === 'bilder')
            @if ($images->isEmpty())
                <div class="empty" style="padding:20px">Der Artikel hat noch keine Bilder.</div>
            @else
                <p class="small muted" style="margin-top:0">Haken setzen, welche Bilder zu dieser Variante gehören. Ein Bild ohne Zuordnung gilt für alle Varianten.</p>
                <form wire:submit="saveImages">
                    <div class="gallery">
                        @foreach ($images as $i => $image)
                            <label style="display:block;cursor:pointer" wire:key="vi{{ $image->id }}">
                                <img src="{{ $image->sized(300) }}" alt="{{ $image->alt }}" loading="lazy" @style(['outline: 3px solid var(--accent)' => in_array($image->id, $imageIds)])>
                                <span class="check small" style="margin-top:6px"><input type="checkbox" value="{{ $image->id }}" wire:model.live="imageIds">
                                    {{ $i === 0 ? 'Hauptbild' : ($i + 1).'. Bild' }}
                                    @if (! $image->isAssigned())<span class="muted">· alle</span>@elseif (! in_array($variant->id, $image->variant_ids ?? [], true))<span class="muted">· andere Varianten</span>@endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <button class="btn btn-primary btn-sm" type="submit" style="margin-top:12px">Zuordnung speichern <span class="spin" wire:loading wire:target="saveImages"></span></button>
                </form>
            @endif

        @elseif ($section === 'maerkte')
            <div class="table-wrap">
                <table class="list">
                    <thead><tr><th>Kanal</th><th class="num">Preis</th><th>Angebot</th><th>Kennung</th></tr></thead>
                    <tbody>
                    @foreach ($channels as $ch)
                        @php $listing = $variant->listings->firstWhere('channel_id', $ch->id); @endphp
                        <tr>
                            <td>{{ $ch->name }}</td>
                            <td class="num">{{ Format::money($variant->priceOn($ch)) }}@if (! $ch->isShopify() && ! $variant->channelPrices->firstWhere('channel_id', $ch->id))<div class="small muted">wie Shop</div>@endif</td>
                            <td>
                                @if ($ch->isShopify())
                                    {{ $product->shopify_id ? 'im Shop' : 'noch nicht angelegt' }}
                                @elseif ($listing)
                                    <span class="badge {{ ['active' => 'badge-ok', 'error' => 'badge-bad', 'pending' => 'badge-amber'][$listing->status] ?? '' }}">{{ ['active' => 'aktiv', 'inactive' => 'inaktiv', 'pending' => 'in Prüfung', 'error' => 'Fehler', 'unknown' => 'unbekannt'][$listing->status] ?? $listing->status }}</span>
                                @else
                                    <span class="muted">kein Angebot</span>
                                @endif
                            </td>
                            <td class="mono small">{{ $listing?->external_id ?? ($ch->key === 'amazon' && $variant->asin ? $variant->asin.' (hinterlegt)' : '–') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="small muted">Preise je Kanal änderst du im Reiter „Preise je Kanal“, Angebote im Reiter „Marktplätze“.</p>
        @endif
    </div>
</section>
