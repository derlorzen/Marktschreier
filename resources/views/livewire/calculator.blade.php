@php
    use App\Support\Format;
    $rows = [
        ['Gewinn je Stück', 'unit_profit', 'money'],
        ['Marge vom Nettopreis', 'margin', 'pct'],
        ['Umsatz im Monat', 'revenue', 'money'],
        ['Gewinn im Monat nach Werbung', 'month_profit', 'money'],
        ['Werbekosten vom Umsatz (ACoS)', 'acos', 'pct'],
        ['Werbekosten-Grenze (Break-even-ACoS)', 'break_even_acos', 'pct'],
        ['Preis ohne Gewinn und Verlust', 'min_price', 'money'],
        ['Preis für '.$targetMargin.' % Zielmarge', 'target_price', 'money'],
    ];
    $fmt = fn ($v, $type) => $v === null ? '–' : ($type === 'pct' ? Format::percent($v) : Format::money($v));
@endphp
<div>
    <div class="page-head">
        <h1>Was-wäre-wenn-Rechner</h1>
        <span class="muted small">Was passiert mit dem Gewinn, wenn sich Preis, Einkauf, Menge oder Werbung ändern?</span>
    </div>

    <div class="card card-pad" style="margin-bottom:16px">
        <div class="grid-3">
            <div class="field">Artikel <span class="hint">optional, füllt die Werte aus</span>
                @if ($variant)
                    <div class="row"><strong>{{ $variant->product->title }}{{ $variant->label() !== 'Standard' ? ' ('.$variant->label().')' : '' }}</strong>
                        <button class="linkbtn small" wire:click="clearVariant">ändern</button></div>
                @else
                    <input class="input" wire:model.live.debounce.300ms="search" placeholder="Titel, SKU oder GTIN">
                    @foreach ($results as $r)
                        <button type="button" class="btn btn-sm" style="margin-top:4px;justify-content:flex-start" wire:click="pick({{ $r['id'] }})">{{ \Illuminate\Support\Str::limit($r['label'], 60) }}</button>
                    @endforeach
                @endif
            </div>
            <label class="field">Kanal <span class="hint">Gebühren {{ $channel->feeText() }}, Versand {{ Format::money($channel->shipping_cost) }}</span>
                <select class="input" wire:model.live="channelId">
                    @foreach ($channels as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                </select>
            </label>
            <label class="field">Zielmarge in %
                <input class="input num" style="max-width:120px" wire:model.live.debounce.400ms="targetMargin" inputmode="decimal">
            </label>
        </div>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table class="list">
                <thead><tr><th></th><th class="num">Heute</th><th class="num">Was wäre wenn</th><th class="num">Unterschied</th></tr></thead>
                <tbody>
                    @foreach ([['Preis brutto €', 'price'], ['Einkauf netto €', 'cost'], ['Stück im Monat', 'units'], ['Werbebudget im Monat €', 'ads']] as [$label, $key])
                        <tr>
                            <td><strong>{{ $label }}</strong></td>
                            <td style="width:180px"><input class="input input-sm num" wire:model.live.debounce.400ms="now.{{ $key }}" inputmode="decimal" aria-label="{{ $label }} heute"></td>
                            <td style="width:180px"><input class="input input-sm num" wire:model.live.debounce.400ms="plan.{{ $key }}" inputmode="decimal" aria-label="{{ $label }} geplant"></td>
                            <td></td>
                        </tr>
                    @endforeach
                    @foreach ($rows as [$label, $key, $type])
                        @php $d = $a[$key] !== null && $b[$key] !== null ? $b[$key] - $a[$key] : null; @endphp
                        <tr>
                            <td>{{ $label }}</td>
                            <td class="num">{{ $fmt($a[$key], $type) }}</td>
                            <td class="num"><strong @style(['color: var(--bad)' => in_array($key, ['unit_profit', 'month_profit']) && $b[$key] < 0])>{{ $fmt($b[$key], $type) }}</strong></td>
                            <td class="num" @style(['color: var(--accent-strong)' => $d > 0.004 && $key !== 'acos', 'color: var(--warn-fg)' => $d < -0.004 && $key !== 'acos'])>
                                @if ($d !== null && abs($d) >= 0.005){{ $d > 0 ? '+' : '−' }}{{ $type === 'pct' ? number_format(abs($d), 1, ',', '.').' Pkt.' : Format::money(abs($d)) }}@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="notice" style="margin-top:16px">
        @if ($unitsToMatch === null)
            Mit diesem Plan bleibt je Stück kein Gewinn, egal wie viel verkauft wird.
        @else
            Damit der Plan genauso viel Gewinn bringt wie heute, müssten im Monat <strong>{{ number_format(ceil($unitsToMatch), 0, ',', '.') }} Stück</strong> verkauft werden
            @if ($a['units'] > 0)({{ $unitsToMatch >= $a['units'] ? '+' : '−' }}{{ number_format(abs($unitsToMatch - $a['units']) / $a['units'] * 100, 0, ',', '.') }} % zu heute)@endif.
        @endif
        @if ($b['acos'] !== null && $b['break_even_acos'] !== null && $b['acos'] > $b['break_even_acos'])
            <br><strong style="color:var(--bad)">Die Werbung kostet mehr, als der Artikel verdient.</strong>
        @endif
    </div>
</div>
