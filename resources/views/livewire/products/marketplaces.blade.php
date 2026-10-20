@php use App\Support\Format; @endphp
<div>
    @if ($notice)
        <div class="notice notice-ok row" role="status" style="margin-bottom:12px"><span>{{ $notice }}</span>
            <button class="linkbtn" style="margin-left:auto" wire:click="$set('notice', null)">Ausblenden</button></div>
    @endif
    @if ($error)
        <div class="notice notice-bad row" style="margin-bottom:12px"><span>{{ $error }}</span>
            <button class="linkbtn" style="margin-left:auto" wire:click="$set('error', null)">Ausblenden</button></div>
    @endif

    @foreach ($providers as $provider => $p)
        <section style="margin-bottom:24px" wire:key="mp-{{ $provider }}">
            <div class="row" style="margin-bottom:8px">
                <h3>{{ $p['channel']->name }}</h3>
                @if (! $p['connected'])
                    <span class="badge">nicht verbunden</span>
                    @can('admin')<a class="small" href="{{ route('settings.'.$provider) }}" wire:navigate>verbinden</a>@endcan
                @else
                    @if ($p['checked'])<span class="muted small">Stand {{ $p['checked']->timezone('Europe/Berlin')->format('d.m. H:i') }}</span>@endif
                    <span style="flex:1"></span>
                    @if ($p['pending'])
                        <button class="btn btn-sm btn-primary" wire:click="pushPrices('{{ $provider }}')">{{ $p['pending'] }} {{ $p['pending'] === 1 ? 'Preis' : 'Preise' }} übertragen <span class="spin" wire:loading wire:target="pushPrices"></span></button>
                    @endif
                    @if ($provider === 'ebay' && ! $p['hasListing'] && ! $ebayForm)
                        <button class="btn btn-sm" wire:click="$set('ebayForm', true)">Bei eBay einstellen</button>
                    @endif
                @endif
            </div>

            @if ($p['connected'])
                <div class="table-wrap">
                <table class="list">
                    <thead><tr><th>Variante</th><th>Status</th><th>{{ $provider === 'amazon' ? 'ASIN' : 'Artikelnummer' }}</th><th class="num">Preis dort</th><th class="num">Preis hier</th><th>Hinweis</th></tr></thead>
                    <tbody>
                    @foreach ($p['rows'] as $row)
                        @php
                            $l = $row['listing'];
                            $v = $row['variant'];
                            [$label, $class] = $l ? $l->statusLabel() : ['Nicht gefunden', ''];
                            $url = $l?->external_id ? ($provider === 'amazon' ? 'https://www.amazon.de/dp/'.$l->external_id : 'https://www.ebay.de/itm/'.$l->external_id) : null;
                        @endphp
                        <tr wire:key="{{ $provider }}-{{ $v->id }}">
                            <td class="nowrap"><strong>{{ $v->label() }}</strong><div class="small muted mono">{{ $v->sku }}</div></td>
                            <td><span class="badge {{ $class }}">{{ $label }}</span></td>
                            <td class="mono small">@if ($url)<a href="{{ $url }}" target="_blank" rel="noopener">{{ $l->external_id }}</a>@else – @endif</td>
                            <td class="num">{{ Format::money($l?->remote_price) }}</td>
                            <td class="num">
                                {{ Format::money($row['price']) }}
                                @if ($l && $l->pricePending($row['price']))<div class="small" style="color:var(--warn-fg)">nicht übertragen</div>@endif
                            </td>
                            <td class="small" style="max-width:320px">
                                @if ($l?->last_error)<span style="color:var(--bad)">{{ $l->last_error }}</span>
                                @elseif ($l?->changedRemotely())<span class="muted">Preis auf {{ $p['channel']->name }} geändert</span>
                                @elseif (! $l || $l->status === 'inactive')
                                    @if ($provider === 'amazon')
                                        @if (isset($amazonMatches[$v->id]) && $amazonMatches[$v->id])
                                            <div class="stack" style="--gap:6px">
                                                @foreach ($amazonMatches[$v->id] as $m)
                                                    <label class="check" style="align-items:flex-start">
                                                        <input type="radio" value="{{ $m['asin'] }}" wire:model="amazonChoice.{{ $v->id }}">
                                                        @if ($m['image'])<img src="{{ $m['image'] }}" alt="" style="width:36px;height:36px;object-fit:contain;border-radius:4px;background:#fff">@endif
                                                        <span><span class="mono">{{ $m['asin'] }}</span> {{ \Illuminate\Support\Str::limit($m['title'], 70) }}</span>
                                                    </label>
                                                @endforeach
                                                <div><button class="btn btn-sm btn-primary" wire:click="amazonCreate({{ $v->id }})">Angebot anlegen <span class="spin" wire:loading wire:target="amazonCreate"></span></button></div>
                                            </div>
                                        @else
                                            <div class="row" style="gap:6px">
                                                <button class="btn btn-sm" wire:click="amazonLookup({{ $v->id }})" @disabled(blank($v->barcode)) title="{{ blank($v->barcode) ? 'GTIN fehlt' : '' }}">Bei Amazon suchen <span class="spin" wire:loading wire:target="amazonLookup({{ $v->id }})"></span></button>
                                                <button class="btn btn-sm" wire:click="$set('newProductFor', {{ $v->id }})">Neu anlegen</button>
                                            </div>
                                        @endif
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            @else
                <p class="muted small" style="margin:0">Ohne Verbindung zeigt Marktschreier hier nur den hinterlegten Preis. Verkäufe auf {{ $p['channel']->name }} kommen trotzdem über Billbee herein.</p>
            @endif

            @if ($provider === 'amazon' && $newProductFor && $p['connected'])
                <livewire:products.amazon-new-product :variant-id="$newProductFor" :key="'anp'.$newProductFor" />
            @endif

            @if ($provider === 'ebay' && $ebayForm && $p['connected'])
                <div class="notice" style="margin-top:12px">
                    <h3 style="margin-bottom:8px">Neues Angebot bei eBay</h3>
                    <p class="small muted" style="margin:0 0 10px">Titel, Beschreibung, Bilder, GTIN und Preise kommen aus diesem Artikel{{ $product->variants()->count() > 1 ? ', alle Varianten in einem Angebot' : '' }}. Startbestand aus Billbee, danach pflegt Billbee den Bestand. Versand, Zahlung und Rücknahme laut eBay-Einstellungen.</p>
                    <form class="row" wire:submit="ebaySearch">
                        <input class="input" style="flex:1 1 300px" wire:model="ebayQuery" aria-label="Suchbegriff für die Kategorie">
                        <button class="btn btn-sm" type="submit">Kategorie suchen <span class="spin" wire:loading wire:target="ebaySearch"></span></button>
                        <button class="btn btn-sm" type="button" wire:click="$set('ebayForm', false)">Abbrechen</button>
                    </form>
                    @if ($ebayCategories)
                        <div class="stack" style="margin-top:10px">
                            @foreach ($ebayCategories as $cat)
                                <label class="check"><input type="radio" name="ebaycat" value="{{ $cat['id'] }}" wire:click="ebayPickCategory('{{ $cat['id'] }}')" @checked($ebayCategoryId === $cat['id'])> {{ $cat['path'] }} <span class="muted mono small">{{ $cat['id'] }}</span></label>
                            @endforeach
                        </div>
                    @endif
                    @if ($ebayCategoryId && $ebayAspects)
                        <div class="grid-2" style="margin-top:14px">
                            @foreach ($ebayAspects as $a)
                                <label class="field">{{ $a['name'] }} @if ($a['required'])<span class="hint" style="color:var(--bad)">Pflicht</span>@else<span class="hint">empfohlen</span>@endif
                                    @if (! $a['free'] && $a['values'])
                                        <select class="input input-sm" wire:model="ebaySpecifics.{{ $a['name'] }}">
                                            <option value="">Bitte wählen</option>
                                            @foreach ($a['values'] as $val)<option value="{{ $val }}">{{ $val }}</option>@endforeach
                                        </select>
                                    @else
                                        <input class="input input-sm" wire:model="ebaySpecifics.{{ $a['name'] }}" list="asp-{{ md5($a['name']) }}">
                                        @if ($a['values'])<datalist id="asp-{{ md5($a['name']) }}">@foreach ($a['values'] as $val)<option value="{{ $val }}">@endforeach</datalist>@endif
                                    @endif
                                </label>
                            @endforeach
                        </div>
                        @if ($ebayCheck && $ebayCheck['warnings'])
                            <ul class="small" style="margin:10px 0 0;color:var(--warn-fg)">@foreach ($ebayCheck['warnings'] as $w)<li>{{ $w }}</li>@endforeach</ul>
                        @endif
                        <div class="row" style="margin-top:12px">
                            <button class="btn" wire:click="ebayVerify">Bei eBay prüfen lassen <span class="spin" wire:loading wire:target="ebayVerify"></span></button>
                            <button class="btn btn-primary" wire:click="ebayPublish" @disabled(! $ebayCheck)>Einstellen <span class="spin" wire:loading wire:target="ebayPublish"></span></button>
                        </div>
                    @endif
                </div>
            @endif
        </section>
    @endforeach
</div>
