@php
    $m = fn ($v, $d = 2) => $v === null ? '–' : number_format((float) $v, $d, ',', '.').' €';
    $pct = function ($a, $b) { return $b > 0 ? (($a - $b) >= 0 ? '+' : '−').number_format(abs(($a - $b) / $b * 100), 0, ',', '.').' %' : '–'; };
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>{{ $r['title'] }}</title>
<style>
    @page { margin: 22mm 18mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #15171C; }
    h1 { font-size: 17pt; margin: 0 0 2mm; }
    h2 { font-size: 11.5pt; margin: 8mm 0 2mm; }
    .muted { color: #5B616E; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 2mm 2mm; border-bottom: 0.3mm solid #E2E5EA; text-align: left; }
    th { font-size: 8.5pt; text-transform: uppercase; color: #5B616E; background: #F6F7F9; }
    .r { text-align: right; }
    .sum td { font-weight: bold; border-top: 0.5mm solid #15171C; }
    .kpis td { border: 0; padding: 0 3mm 0 0; vertical-align: top; width: 25%; }
    .kpi { border: 0.3mm solid #E2E5EA; border-radius: 2mm; padding: 3mm; }
    .kpi b { display: block; font-size: 13pt; margin-top: 1mm; }
    .note { font-size: 8.5pt; color: #5B616E; margin-top: 6mm; }
</style>
</head>
<body>
    <h1>{{ $r['title'] }}</h1>
    <div class="muted">{{ $r['company'] ?: 'Marktschreier' }} · erstellt am {{ $r['generated_at'] }}</div>

    <table class="kpis" style="margin-top:6mm"><tr>
        <td><div class="kpi">Umsatz brutto<b>{{ $m($r['total']['gross'], 0) }}</b><span class="muted">{{ $pct($r['total']['gross'], $r['previous']['gross']) }} zum Vormonat</span></div></td>
        <td><div class="kpi">Rohertrag<b>{{ $m($r['total']['profit'], 0) }}</b><span class="muted">{{ $pct($r['total']['profit'], $r['previous']['profit']) }} zum Vormonat</span></div></td>
        <td><div class="kpi">Werbekosten<b>{{ $m($r['total']['ad_cost'], 0) }}</b><span class="muted">ACoS {{ $r['ads']['acos'] === null ? '–' : number_format($r['ads']['acos'], 1, ',', '.').' %' }}</span></div></td>
        <td><div class="kpi">Nach Werbung<b>{{ $m($r['total']['after_ads'], 0) }}</b><span class="muted">{{ $r['total']['orders'] }} Bestellungen</span></div></td>
    </tr></table>

    <h2>Umsatz je Kanal</h2>
    <table>
        <thead><tr><th>Kanal</th><th class="r">Bestellungen</th><th class="r">Brutto</th><th class="r">Netto</th><th class="r">Umsatzsteuer</th><th class="r">Rohertrag</th></tr></thead>
        <tbody>
        @foreach ($r['channels'] as $c)
            <tr><td>{{ $c['name'] }}</td><td class="r">{{ $c['orders'] }}</td><td class="r">{{ $m($c['gross']) }}</td><td class="r">{{ $m($c['net']) }}</td><td class="r">{{ $m($c['vat']) }}</td><td class="r">{{ $m($c['profit']) }}</td></tr>
        @endforeach
        <tr class="sum"><td>Gesamt</td><td class="r">{{ $r['total']['orders'] }}</td><td class="r">{{ $m($r['total']['gross']) }}</td><td class="r">{{ $m($r['total']['net']) }}</td><td class="r">{{ $m($r['total']['vat']) }}</td><td class="r">{{ $m($r['total']['profit']) }}</td></tr>
        </tbody>
    </table>

    <h2>Werbung (Amazon Ads)</h2>
    <table>
        <tr><td>Kosten</td><td class="r">{{ $m($r['ads']['cost']) }}</td><td>Umsatz über Werbung</td><td class="r">{{ $m($r['ads']['sales']) }}</td></tr>
        <tr><td>Klicks</td><td class="r">{{ number_format($r['ads']['clicks'], 0, ',', '.') }}</td><td>Bestellungen über Werbung</td><td class="r">{{ $r['ads']['orders'] }}</td></tr>
    </table>

    <h2>Artikel mit dem meisten Rohertrag</h2>
    <table>
        <thead><tr><th>Artikel</th><th class="r">Stück</th><th class="r">Umsatz brutto</th><th class="r">Rohertrag</th></tr></thead>
        <tbody>
        @forelse ($r['top'] as $t)
            <tr><td>{{ $t['name'] }}</td><td class="r">{{ number_format($t['quantity'], 0, ',', '.') }}</td><td class="r">{{ $m($t['gross']) }}</td><td class="r">{{ $m($t['profit']) }}</td></tr>
        @empty
            <tr><td colspan="4" class="muted">Keine zugeordneten Verkäufe.</td></tr>
        @endforelse
        </tbody>
    </table>

    <p class="note">
        Rohertrag = Nettoumsatz minus Einkaufspreis aus Billbee, Kanalgebühren und Versandkosten laut den Einstellungen in Marktschreier. Ohne Fixkosten.
        Stornierte und gelöschte Bestellungen sind nicht enthalten. Umsatzsteuer wie in Billbee je Position ausgewiesen.
        @if ($r['total']['items_without_cost']) {{ $r['total']['items_without_cost'] }} Positionen ohne Einkaufspreis sind im Rohertrag nicht berücksichtigt. @endif
        Diese Auswertung ersetzt keine Buchhaltung.
    </p>
</body>
</html>
