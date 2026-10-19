<?php

namespace App\Services\Alerts;

use App\Models\Alert;
use App\Models\Channel;
use App\Models\Variant;
use App\Services\Opportunities\OpportunityFinder;
use App\Services\Sales\SalesStats;
use App\Support\Format;

/** Wochenrückblick: Zahlen der letzten 7 Tage, Vergleich zur Vorwoche, Chancen, offene Hinweise. */
class WeeklyReview
{
    public function __construct(private SalesStats $stats, private OpportunityFinder $finder)
    {
    }

    public function build(): array
    {
        $now = $this->stats->period(7);
        $before = $this->stats->period(7, now()->subDays(7));
        $pct = fn ($a, $b) => $b > 0 ? (($a - $b) >= 0 ? '+' : '−').number_format(abs(($a - $b) / $b * 100), 0, ',', '.').' % zur Vorwoche' : 'kein Vergleich';
        $channels = Channel::all()->keyBy('id');
        $top = collect($now['variants'])->sortByDesc('quantity')->take(3)->keys();
        $variants = Variant::with('product:id,title')->whereIn('id', $top)->get()->keyBy('id');

        return [
            'period' => now()->subDays(7)->format('d.m.').' bis '.now()->subDay()->format('d.m.Y'),
            'revenue' => Format::money($now['total']['revenue_gross'], 0),
            'revenue_change' => $pct($now['total']['revenue_gross'], $before['total']['revenue_gross']),
            'profit' => Format::money($now['total']['profit'], 0),
            'profit_change' => $pct($now['total']['profit'], $before['total']['profit']),
            'orders' => (string) $now['total']['orders'],
            'channels' => collect($now['channels'])->map(fn ($c, $id) => [
                'name' => $id === 'none' ? 'Ohne Kanal' : ($channels->get($id)?->name ?? '?'),
                'revenue' => Format::money($c['revenue_gross'], 0), 'profit' => Format::money($c['profit'], 0), 'sort' => $c['revenue_gross'],
            ])->sortByDesc('sort')->values()->all(),
            'top' => $top->map(fn ($id) => $variants->get($id)?->product->title.' ('.(int) $now['variants'][$id]['quantity'].' Stück)')->filter()->values()->all(),
            'chances' => $this->finder->all()->take(5)->map(fn ($o) => $o->title.($o->monthlyValue ? ' (bis '.Format::money($o->monthlyValue, 0).' im Monat)' : ''))->values()->all(),
            'alerts' => Alert::open()->latest('updated_at')->take(8)->pluck('title')->all(),
        ];
    }
}
