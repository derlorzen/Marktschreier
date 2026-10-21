@php
    use App\Models\Product;
    use App\Support\Format;
    [$syncLabel, $syncClass] = Format::pushStatusBadge($product->push_status);
    $changed = fn (string $field) => array_key_exists($field, $pending['product']);
    $vChanged = fn (int $id, string $field) => array_key_exists($field, $pending['variants'][$id] ?? []);
    $tabs = ['stamm' => 'Stammdaten', 'varianten' => 'Varianten und Preise', 'preise' => 'Preise je Kanal', 'marktplaetze' => 'Marktplätze', 'texte' => 'Texte je Kanal', 'bilder' => 'Bilder', 'merkmale' => 'Merkmale', 'verlauf' => 'Verlauf'];
@endphp
<div>
    <x-article-tabs :active="$product->id" />

    <div class="page-head">
        @if ($img = $images->first())<img class="thumb" src="{{ $img->sized(80) }}" alt="">@endif
        <div style="min-width:0">
            <h1>{{ $product->title }}</h1>
            <div class="muted small">
                {{ $product->vendor ?: 'Ohne Marke' }} · {{ $product->variants->count() }} {{ $product->variants->count() === 1 ? 'Variante' : 'Varianten' }}
                @if ($shopifyAdminUrl) · <a href="{{ $shopifyAdminUrl }}" target="_blank" rel="noopener">In Shopify öffnen</a>@endif
            </div>
        </div>
        <span class="spacer"></span>
        <span class="badge {{ $syncClass }}">{{ $syncLabel }}</span>
        <button class="btn" wire:click="save" wire:loading.attr="disabled">Speichern <span class="spin" wire:loading wire:target="save"></span></button>
        @if ($product->shopify_id || $shopifyConfigured)
        <button class="btn btn-primary" wire:click="push" wire:loading.attr="disabled" title="Speichert und überträgt alle offenen Änderungen zu Shopify">
            {{ $product->shopify_id ? 'Speichern und zu Shopify' : 'In Shopify anlegen' }} <span class="spin" wire:loading wire:target="push"></span>
        </button>
        @endif
    </div>

    {{-- Kennzahlen direkt sichtbar, ohne Aufklappen --}}
    <div class="kpis">
        <div class="kpi"><div class="label">Preis im Shop</div><div class="value">{{ Format::money($product->variants->min('price')) }}@if ($product->variants->max('price') != $product->variants->min('price')) <span class="small muted">bis {{ Format::money($product->variants->max('price')) }}</span>@endif</div></div>
        <div class="kpi"><div class="label">EK netto (Billbee)</div><div class="value">{{ Format::money($costMin) }}</div></div>
        <div class="kpi"><div class="label">Marge im Shop (Ø)</div><div class="value">{{ Format::percent($marginAvg) }}</div></div>
        <div class="kpi"><div class="label">Bestand (Billbee)</div><div class="value">{{ $hasStock ? number_format($stock, 0, ',', '.') : '–' }}</div></div>
        <div class="kpi"><div class="label">Verkauft (30 Tage)</div><div class="value">{{ $hasOrders ? number_format($sold30, 0, ',', '.') : '–' }}</div></div>
        <div class="kpi"><div class="label">Status in Shopify</div><div class="value" style="font-size:16px">{{ Format::shopifyStatus($product->status) }}</div></div>
    </div>

    @if ($notice)
        <div class="notice {{ $noticeType === 'bad' ? 'notice-bad' : 'notice-ok' }} row" role="status">
            <span>{{ $notice }}</span>
            <button class="linkbtn" wire:click="$set('notice', null)" style="margin-left:auto">Ausblenden</button>
        </div>
    @endif

    @if ($product->push_status === Product::STATUS_CONFLICT)
        <div class="notice notice-warn"><strong>Konflikt:</strong> {{ $product->push_error }}</div>
    @elseif ($product->push_status === Product::STATUS_ERROR && $product->push_error)
        <div class="notice notice-bad"><strong>Letzte Übertragung fehlgeschlagen:</strong> {{ $product->push_error }}</div>
    @endif

    @if (session('flash'))<div class="notice notice-ok" style="margin-bottom:12px">{{ session('flash') }}</div>@endif
    @if ($product->plenty_id && ! $product->shopify_id)
        <div class="notice" style="margin-bottom:12px"><strong>Aus PlentyONE (Artikel {{ $product->plenty_id }}).</strong> PlentyONE bleibt bis zum Umzug führend. Hier geänderte Felder bleiben in Marktschreier und werden beim nächsten Import nicht überschrieben.
            @if ($shopifyConfigured) Mit „In Shopify anlegen“ kann der Artikel in den neuen Shop übernommen werden. @endif</div>
    @elseif (! $product->shopify_id)
        <div class="notice" style="margin-bottom:12px"><strong>Nur in Marktschreier.</strong> Mit „In Shopify anlegen“ entsteht der Artikel als Entwurf in Shopify, danach können Bilder hochgeladen und Marktplätze bedient werden.
            @if ($product->push_error)<div class="error">{{ $product->push_error }}</div>@endif</div>
    @elseif ($pendingCount > 0)
        <div class="notice notice-warn">
            <div class="row" style="margin-bottom:8px">
                <strong>{{ $pendingCount }} {{ $pendingCount === 1 ? 'Änderung ist' : 'Änderungen sind' }} noch nicht in Shopify</strong>
                <span class="spacer" style="flex:1"></span>
                <button class="btn btn-sm" wire:click="revert" wire:confirm="Alle lokalen Änderungen verwerfen und den Stand aus Shopify wiederherstellen?">Verwerfen</button>
                <button class="btn btn-sm btn-warn" wire:click="push" wire:loading.attr="disabled">Jetzt übertragen <span class="spin" wire:loading wire:target="push"></span></button>
            </div>
            <table class="diff">
                @foreach ($pending['product'] as $field => $c)
                    <tr><td class="nowrap">{{ Product::fieldLabel($field) }}</td>
                        <td class="clip old">{{ (is_array($c['old']) ? implode(', ', $c['old']) : \Illuminate\Support\Str::limit(strip_tags((string) $c['old']), 120)) ?: 'leer' }}</td>
                        <td class="clip new">{{ (is_array($c['new']) ? implode(', ', $c['new']) : \Illuminate\Support\Str::limit(strip_tags((string) $c['new']), 120)) ?: 'leer' }}</td></tr>
                @endforeach
                @if ($attributesPending)
                    <tr><td class="nowrap">Merkmale</td><td class="old" colspan="2" style="text-decoration:none">werden als Metafelder „marktschreier.…“ übertragen</td></tr>
                @endif
                @foreach ($pending['variants'] as $vid => $fields)
                    @php $variant = $product->variants->firstWhere('id', $vid); @endphp
                    @foreach ($fields as $field => $c)
                        <tr><td class="nowrap">{{ Product::fieldLabel($field) }} <span class="muted">({{ $variant?->label() }})</span></td>
                            <td class="old">{{ in_array($field, ['price', 'compare_at_price']) ? Format::money($c['old']) : ($c['old'] ?: 'leer') }}</td>
                            <td class="new">{{ in_array($field, ['price', 'compare_at_price']) ? Format::money($c['new']) : ($c['new'] ?: 'leer') }}</td></tr>
                    @endforeach
                @endforeach
            </table>
        </div>
    @endif

    <div class="card" style="margin-top:16px">
        <div class="tabs" role="tablist">
            @foreach ($tabs as $key => $label)
                <button class="tab" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')">
                    {{ $label }}
                    @if ($key === 'varianten' && count($pending['variants']))<span class="badge badge-warn">{{ collect($pending['variants'])->sum(fn ($f) => count($f)) }}</span>@endif
                    @if ($key === 'merkmale' && count($attrs))<span class="badge">{{ count($attrs) }}</span>@endif
                </button>
            @endforeach
        </div>

        <div class="card-pad">
        @if ($tab === 'stamm')
            <div class="grid-2">
                <label class="field span-2">Titel
                    <input @class(['input', 'is-changed' => $changed('title')]) wire:model="form.title" maxlength="255">
                    @error('form.title') <span class="error">{{ $message }}</span> @enderror
                </label>
                <label class="field">Marke
                    <input @class(['input', 'is-changed' => $changed('vendor')]) wire:model="form.vendor">
                </label>
                <label class="field">Produktart
                    <input @class(['input', 'is-changed' => $changed('product_type')]) wire:model="form.product_type">
                </label>
                <label class="field">Status in Shopify
                    <select @class(['input', 'is-changed' => $changed('status')]) wire:model="form.status">
                        <option value="ACTIVE">Aktiv</option>
                        <option value="DRAFT">Entwurf</option>
                        <option value="ARCHIVED">Archiviert</option>
                        <option value="UNLISTED">Nicht gelistet</option>
                    </select>
                </label>
                <label class="field">Tags <span class="hint">mit Komma trennen</span>
                    <input @class(['input', 'is-changed' => $changed('tags')]) wire:model="form.tags">
                </label>
                <label class="field span-2">Beschreibung <span class="hint">HTML, so wie es in Shopify steht</span>
                    <textarea @class(['input', 'mono', 'is-changed' => $changed('description_html')]) wire:model="form.description_html" rows="12"></textarea>
                </label>
                <label class="field" x-data="{ n: $wire.form.seo_title?.length ?? 0 }">SEO-Titel <span class="hint"><span x-text="n"></span> von empfohlen 70 Zeichen</span>
                    <input @class(['input', 'is-changed' => $changed('seo_title')]) wire:model="form.seo_title" x-on:input="n = $event.target.value.length" placeholder="leer = Titel">
                </label>
                <label class="field" x-data="{ n: $wire.form.seo_description?.length ?? 0 }">SEO-Beschreibung <span class="hint"><span x-text="n"></span> von empfohlen 160 Zeichen</span>
                    <textarea @class(['input', 'is-changed' => $changed('seo_description')]) wire:model="form.seo_description" x-on:input="n = $event.target.value.length" rows="3" style="min-height:80px"></textarea>
                </label>
            </div>

        @elseif ($tab === 'varianten')
            <p class="muted small" style="margin-top:0">Der Artikel ist der Vater, die Varianten sind die Kinder. Preis, Streichpreis, SKU und GTIN pflegst du hier und überträgst sie zu Shopify. Mit Klick auf eine Variante öffnest du ihre Einstellungen, Merkmale, Bilder und Marktplätze. EK und Bestand kommen aus Billbee und sind nur zur Ansicht. Preise brutto, Marge netto bei {{ rtrim(rtrim(number_format($vat, 1, ',', ''), '0'), ',') }} % MwSt.</p>
            <div class="table-wrap">
            <table class="list">
                <thead><tr>
                    <th>Variante</th><th>SKU</th><th>GTIN/EAN</th><th>ASIN</th><th class="num">Preis</th><th class="num">Streichpreis</th>
                    <th class="num">EK netto</th><th class="num">Marge</th><th class="num">Bestand</th>
                </tr></thead>
                <tbody>
                @foreach ($product->variants as $v)
                    @php
                        $m = $v->marginNet($vat);
                        $net = (float) $v->price / (1 + $vat / 100);
                    @endphp
                    <tr wire:key="v{{ $v->id }}" @class(['is-selected' => $openVariant === $v->id])>
                        <td class="nowrap">
                            <button class="linkbtn" style="font-weight:600" wire:click="openVariantDetail({{ $v->id }})" aria-expanded="{{ $openVariant === $v->id ? 'true' : 'false' }}">{{ $v->label() }}</button>
                            <div class="row" style="gap:4px;margin-top:2px">
                                @if ($v->is_main)<span class="badge badge-ok">Hauptvariante</span>@endif
                                @if (! $v->active)<span class="badge badge-warn">inaktiv</span>@endif
                                @if (! $v->billbee_id)<span class="badge badge-warn" title="Keine passende SKU/GTIN in Billbee gefunden">nicht in Billbee</span>@endif
                            </div>
                        </td>
                        <td style="min-width:150px"><input @class(['input input-sm mono', 'is-changed' => $vChanged($v->id, 'sku')]) wire:model="variants.{{ $v->id }}.sku" aria-label="SKU {{ $v->label() }}">
                            @error("variants.$v->id.sku") <div class="error">{{ $message }}</div> @enderror</td>
                        <td style="min-width:160px"><input @class(['input input-sm mono', 'is-changed' => $vChanged($v->id, 'barcode')]) wire:model="variants.{{ $v->id }}.barcode" inputmode="numeric" aria-label="GTIN {{ $v->label() }}">
                            @error("variants.$v->id.barcode") <div class="error">{{ $message }}</div> @enderror</td>
                        <td class="mono small nowrap">@if ($v->asin)<a href="https://www.amazon.de/dp/{{ $v->asin }}" target="_blank" rel="noopener">{{ $v->asin }}</a>@else<span class="muted">–</span>@endif</td>
                        <td style="min-width:110px"><input @class(['input input-sm num', 'is-changed' => $vChanged($v->id, 'price')]) wire:model="variants.{{ $v->id }}.price" inputmode="decimal" aria-label="Preis {{ $v->label() }}">
                            @error("variants.$v->id.price") <div class="error">{{ $message }}</div> @enderror</td>
                        <td style="min-width:110px"><input @class(['input input-sm num', 'is-changed' => $vChanged($v->id, 'compare_at_price')]) wire:model="variants.{{ $v->id }}.compare_at_price" inputmode="decimal" placeholder="keiner" aria-label="Streichpreis {{ $v->label() }}">
                            @error("variants.$v->id.compare_at_price") <div class="error">{{ $message }}</div> @enderror</td>
                        <td class="num">{{ Format::money($v->cost_net) }}</td>
                        <td class="num">
                            @if ($m !== null)
                                <span @style(['color: var(--bad)' => $m < 0])>{{ Format::money($m) }}</span>
                                <div class="muted small">{{ $net > 0 ? Format::percent($m / $net * 100) : '–' }}</div>
                            @else – @endif
                        </td>
                        <td class="num">{{ $v->stock === null ? '–' : number_format($v->stock, 0, ',', '.') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
            <p class="muted small">Marge und EK beziehen sich auf den gespeicherten Preis.</p>
            @if ($openVariant)
                <livewire:products.variant-detail :product="$product" :variant-id="$openVariant" :key="'vd'.$openVariant" />
            @endif
            @if ($product->shopify_id && collect($product->options ?? [])->reject(fn ($o) => in_array($o['name'], ['Title', 'Titel']))->isNotEmpty())
                @if (! $showAddVariant)
                    <button class="btn btn-sm" wire:click="$set('showAddVariant', true)">Variante hinzufügen</button>
                @else
                    <form class="notice" wire:submit="addVariant">
                        <h3 style="margin-bottom:8px">Neue Variante</h3>
                        <div class="grid-3">
                            @foreach ($product->options as $i => $opt)
                                <label class="field">{{ $opt['name'] }}
                                    <input class="input input-sm" wire:model="newVariant.options.{{ $i }}" list="optvals{{ $i }}">
                                    <datalist id="optvals{{ $i }}">@foreach ($opt['values'] ?? [] as $val)<option value="{{ $val }}">@endforeach</datalist>
                                </label>
                            @endforeach
                            <label class="field">SKU <input class="input input-sm mono" wire:model="newVariant.sku">@error('newVariant.sku') <span class="error">{{ $message }}</span> @enderror</label>
                            <label class="field">GTIN/EAN <input class="input input-sm mono" wire:model="newVariant.barcode">@error('newVariant.barcode') <span class="error">{{ $message }}</span> @enderror</label>
                            <label class="field">Preis <input class="input input-sm num" wire:model="newVariant.price" inputmode="decimal">@error('newVariant.price') <span class="error">{{ $message }}</span> @enderror</label>
                        </div>
                        @error('newVariant') <div class="error">{{ $message }}</div> @enderror
                        <div class="row" style="margin-top:10px">
                            <button class="btn btn-sm btn-primary" type="submit">In Shopify anlegen <span class="spin" wire:loading wire:target="addVariant"></span></button>
                            <button class="btn btn-sm" type="button" wire:click="$set('showAddVariant', false)">Abbrechen</button>
                        </div>
                    </form>
                @endif
            @endif

        @elseif ($tab === 'preise')
            <div class="table-wrap">
            <table class="list">
                <thead>
                    <tr>
                        <th>Variante</th><th class="num">EK netto</th>
                        @foreach ($channels as $ch)<th class="num">{{ $ch->name }}</th>@endforeach
                    </tr>
                </thead>
                <tbody>
                @foreach ($priceVariants as $v)
                    <tr wire:key="pv{{ $v->id }}">
                        <td class="nowrap"><strong>{{ $v->label() }}</strong></td>
                        <td class="num">{{ Format::money($v->cost_net) }}</td>
                        @foreach ($channels as $ch)
                            @php
                                $price = $v->priceOn($ch);
                                $profit = $calc->profit($price, $v->cost_net !== null ? (float) $v->cost_net : null, $ch);
                                $own = $v->channelPrices->firstWhere('channel_id', $ch->id);
                            @endphp
                            <td class="num" style="min-width:130px">
                                @if ($ch->isShopify())
                                    <strong>{{ Format::money($price) }}</strong>
                                    <div class="small muted">im Reiter Varianten</div>
                                @else
                                    <input class="input input-sm num" wire:model="channelPriceInputs.{{ $v->id }}.{{ $ch->id }}" inputmode="decimal"
                                        placeholder="{{ Format::decimalForInput($v->price) }}" aria-label="Preis {{ $ch->name }} {{ $v->label() }}">
                                    @error("channelPriceInputs.$v->id.$ch->id") <div class="error">{{ $message }}</div> @enderror
                                    @if (! $own)<div class="small muted">wie Shop</div>@endif
                                @endif
                                <div class="small" @style(['color: var(--bad)' => $profit !== null && $profit < 0])>
                                    {{ $profit === null ? 'Gewinn –' : 'Gewinn '.Format::money($profit) }}
                                </div>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
            <div class="row" style="margin-top:12px">
                <button class="btn" wire:click="saveChannelPrices">Kanalpreise speichern <span class="spin" wire:loading wire:target="saveChannelPrices"></span></button>
                <span class="muted small">Leer lassen heißt: Der Marktplatz bekommt den Shop-Preis. Gewinn nach Gebühren und Versand laut <a href="{{ route('pricing') }}" wire:navigate>Kanäle und Preisregeln</a>.</span>
            </div>

            <div class="notice" style="margin-top:20px">
                <h3 style="margin-bottom:6px">Preise mit einem Regelwerk neu rechnen</h3>
                <p class="small" style="margin:0 0 10px">Einmalig: Es bleibt keine Regel am Artikel hängen. Marktplätze dürfen den Preis danach wieder ändern.</p>
                <div class="row">
                    <select class="input input-sm" style="width:auto" wire:model.live="ruleId" aria-label="Regelwerk">
                        @foreach ($priceRules as $rule)<option value="{{ $rule->id }}">{{ $rule->name }}</option>@endforeach
                    </select>
                    @foreach ($channels as $ch)
                        <label class="check"><input type="checkbox" value="{{ $ch->id }}" wire:model.live="ruleChannels"> {{ $ch->name }}</label>
                    @endforeach
                    <label class="check"><input type="checkbox" wire:model.live="onlyIncrease"> Nur erhöhen</label>
                    <button class="btn btn-sm" wire:click="previewRule">Vorschau</button>
                </div>
                @if ($showRulePreview)
                    <div class="table-wrap" style="margin-top:12px">
                    <table class="list">
                        <thead><tr><th>Variante</th><th>Kanal</th><th class="num">Preis jetzt</th><th class="num">Gewinn jetzt</th><th class="num">Neu</th><th class="num">Gewinn neu</th><th>Hinweis</th></tr></thead>
                        <tbody>
                        @foreach ($rulePreview as $row)
                            <tr @if ($row['skip']) style="opacity:.6" @endif>
                                <td>{{ $row['variant']->label() }}</td>
                                <td>{{ $row['channel']->name }}</td>
                                <td class="num">{{ Format::money($row['old']) }}</td>
                                <td class="num">{{ Format::money($row['old_profit']) }}</td>
                                <td class="num"><strong>{{ Format::money($row['new']) }}</strong>
                                    @if ($row['new'] !== null && ! $row['skip'])
                                        <div class="small" style="color: {{ $row['new'] > $row['old'] ? 'var(--accent-strong)' : 'var(--warn-fg)' }}">{{ $row['new'] > $row['old'] ? '+' : '' }}{{ number_format(($row['new'] - $row['old']) / max($row['old'], 0.01) * 100, 0, ',', '.') }} %</div>
                                    @endif
                                </td>
                                <td class="num">{{ Format::money($row['new_profit']) }}</td>
                                <td class="small muted">{{ $row['skip'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    </div>
                    @php $todo = collect($rulePreview)->whereNull('skip')->count(); @endphp
                    <div class="row" style="margin-top:10px">
                        <button class="btn btn-primary" wire:click="applyRule" @disabled(! $todo)>{{ $todo }} {{ $todo === 1 ? 'Preis' : 'Preise' }} übernehmen</button>
                        <button class="btn" wire:click="$set('showRulePreview', false)">Abbrechen</button>
                    </div>
                @endif
            </div>

        @elseif ($tab === 'texte')
            <livewire:products.contents :product="$product" :key="'ct'.$product->id" />
        @elseif ($tab === 'marktplaetze')
            <livewire:products.marketplaces :product="$product" :key="'mp'.$product->id" />
        @elseif ($tab === 'bilder')
            <livewire:products.images :product="$product" :key="'img'.$product->id" />

        @elseif ($tab === 'merkmale')
            <p class="muted small" style="margin-top:0">Merkmale wie Material, Größe oder Kartenanzahl brauchen die Marktplätze für ihre Pflichtfelder (eBay übernimmt passende automatisch). In Shopify landen sie als Metafelder „marktschreier.…“ und lassen sich im Theme anzeigen.</p>
            <div class="stack">
                @foreach ($attrs as $i => $row)
                    <div class="row" wire:key="a{{ $i }}">
                        <input class="input" style="flex:1 1 180px" wire:model="attrs.{{ $i }}.key" placeholder="Merkmal, z. B. Material" aria-label="Merkmal">
                        <input class="input" style="flex:2 1 260px" wire:model="attrs.{{ $i }}.value" placeholder="Wert" aria-label="Wert">
                        <button class="btn btn-sm" wire:click="removeAttribute({{ $i }})" aria-label="Merkmal entfernen">Entfernen</button>
                    </div>
                    @error("attrs.$i.key") <div class="error">{{ $message }}</div> @enderror
                @endforeach
                <div><button class="btn btn-sm" wire:click="addAttribute">Merkmal hinzufügen</button></div>
            </div>

        @elseif ($tab === 'verlauf')
            @if ($logs->isEmpty())
                <div class="empty">Noch keine Änderungen protokolliert.</div>
            @else
                <div class="table-wrap">
                <table class="list">
                    <thead><tr><th>Zeitpunkt</th><th>Quelle</th><th>Feld</th><th>Vorher</th><th>Nachher</th></tr></thead>
                    <tbody>
                    @foreach ($logs as $log)
                        <tr>
                            <td class="nowrap small">{{ $log->created_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</td>
                            <td class="nowrap small">{{ ['user' => $log->user?->name ?? 'Benutzer', 'import' => 'Shopify-Import', 'push' => 'Zu Shopify übertragen', 'billbee' => 'Billbee', 'rule' => 'Preisregel', 'amazon' => 'Amazon'][$log->source] ?? $log->source }}</td>
                            <td class="small">{{ Product::fieldLabel($log->field) }}@if ($log->variant) <span class="muted">({{ $log->variant->label() }})</span>@endif</td>
                            <td class="small muted" style="max-width:280px;overflow-wrap:anywhere">{{ \Illuminate\Support\Str::limit(strip_tags((string) $log->old_value), 140) ?: 'leer' }}</td>
                            <td class="small" style="max-width:280px;overflow-wrap:anywhere">{{ \Illuminate\Support\Str::limit(strip_tags((string) $log->new_value), 140) ?: 'leer' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            @endif
        @endif
        </div>
    </div>
</div>
