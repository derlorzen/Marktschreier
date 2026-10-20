<?php

namespace App\Services\Reports;

use App\Models\AdMetric;
use App\Models\Channel;
use App\Models\Setting;
use App\Models\Variant;
use App\Services\Sales\SalesStats;
use Illuminate\Support\Carbon;

/**
 * Monatsabschluss: Umsatz brutto und netto je Kanal, Umsatzsteuer, Rohertrag nach EK und Kanalkosten,
 * Werbekosten und was danach übrig bleibt. Für die eigene Übersicht und die Buchhaltung.
 */
class MonthlyReport
{
    public function __construct(private SalesStats $stats)
    {
    }

    public function build(int $year, int $month): array
    {
        $from = Carbon::create($year, $month, 1, 0, 0, 0, 'Europe/Berlin')->utc();
        $until = $from->copy()->addMonth();
        $prevFrom = $from->copy()->subMonth();

        $now = $this->stats->between($from, $until);
        $prev = $this->stats->between($prevFrom, $from);
        $channels = Channel::orderBy('position')->get()->keyBy('id');

        $ads = AdMetric::whereBetween('date', [$from->copy()->timezone('Europe/Berlin')->toDateString(), $until->copy()->subDay()->timezone('Europe/Berlin')->toDateString()])
            ->selectRaw('coalesce(sum(cost),0) as cost, coalesce(sum(sales),0) as sales, coalesce(sum(clicks),0) as clicks, coalesce(sum(orders),0) as orders')->first();
        $adCost = (float) $ads->cost;

        $rows = collect($now['channels'])->map(fn ($c, $id) => [
            'name' => $id === 'none' ? 'Ohne Kanal' : ($channels->get($id)?->name ?? 'Unbekannt'),
            'orders' => $c['orders'],
            'gross' => $c['revenue_gross'],
            'net' => $c['revenue_net'],
            'vat' => $c['revenue_gross'] - $c['revenue_net'],
            'profit' => $c['profit'],
            'sort' => $c['revenue_gross'],
        ])->sortByDesc('sort')->values();

        $topIds = collect($now['variants'])->sortByDesc('profit')->take(10)->keys();
        $variants = Variant::with('product:id,title')->whereIn('id', $topIds)->get()->keyBy('id');

        $t = $now['total'];

        return [
            'year' => $year,
            'month' => $month,
            'title' => 'Monatsabschluss '.$from->copy()->timezone('Europe/Berlin')->locale('de')->translatedFormat('F Y'),
            'company' => Setting::get('general.company'),
            'vat_rate' => (float) Setting::get('billbee.vat_rate', 19),
            'total' => [
                'orders' => $t['orders'],
                'gross' => $t['revenue_gross'],
                'net' => $t['revenue_net'],
                'vat' => $t['revenue_gross'] - $t['revenue_net'],
                'profit' => $t['profit'],
                'ad_cost' => $adCost,
                'after_ads' => $t['profit'] - $adCost,
                'avg_order' => $t['orders'] ? $t['revenue_gross'] / $t['orders'] : 0,
                'items_without_cost' => $t['items_without_cost'],
            ],
            'previous' => [
                'gross' => $prev['total']['revenue_gross'],
                'profit' => $prev['total']['profit'],
                'orders' => $prev['total']['orders'],
            ],
            'ads' => [
                'cost' => $adCost, 'sales' => (float) $ads->sales, 'clicks' => (int) $ads->clicks, 'orders' => (int) $ads->orders,
                'acos' => (float) $ads->sales > 0 ? $adCost / (float) $ads->sales * 100 : null,
            ],
            'channels' => $rows->all(),
            'top' => $topIds->map(fn ($id) => [
                'name' => ($variants->get($id)?->product->title ?? '?').(($l = $variants->get($id)?->label()) && $l !== 'Standard' ? " ({$l})" : ''),
                'quantity' => $now['variants'][$id]['quantity'],
                'gross' => $now['variants'][$id]['revenue_gross'],
                'profit' => $now['variants'][$id]['profit'],
            ])->filter(fn ($r) => $r['name'] !== '?')->values()->all(),
            'generated_at' => now()->timezone('Europe/Berlin')->format('d.m.Y H:i'),
        ];
    }

    public function pdf(int $year, int $month): string
    {
        $data = $this->build($year, $month);

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.monthly-pdf', ['r' => $data])->setPaper('a4')->output();
    }

    public static function fileName(int $year, int $month): string
    {
        return sprintf('monatsabschluss-%04d-%02d.pdf', $year, $month);
    }
}
