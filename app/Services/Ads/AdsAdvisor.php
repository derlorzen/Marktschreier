<?php

namespace App\Services\Ads;

use App\Models\AdCampaign;
use App\Models\AdKeyword;
use App\Models\AdMetric;
use App\Models\AdSearchTerm;
use App\Support\Format;

/**
 * Regelbasierte Vorschläge für Werbung, nachvollziehbar statt Blackbox.
 * Ziel-ACoS = Werbekosten in Prozent vom Werbeumsatz, die noch in Ordnung sind.
 */
class AdsAdvisor
{
    public const MIN_CLICKS = 10;

    public function __construct(private AdsClient $client)
    {
    }

    public function targetAcos(): float
    {
        return (float) $this->client->connection()->setting('target_acos', 25);
    }

    /** Kennzahlen je Kampagne für einen Zeitraum */
    public function campaignStats(int $days = 30): array
    {
        $rows = AdMetric::where('date', '>=', now()->subDays($days)->toDateString())
            ->selectRaw('campaign_id, sum(impressions) as impressions, sum(clicks) as clicks, sum(cost) as cost, sum(sales) as sales, sum(orders) as orders, count(*) as days')
            ->groupBy('campaign_id')->get()->keyBy('campaign_id');

        return $rows->map(fn ($r) => [
            'impressions' => (int) $r->impressions, 'clicks' => (int) $r->clicks, 'cost' => (float) $r->cost,
            'sales' => (float) $r->sales, 'orders' => (int) $r->orders, 'days' => (int) $r->days,
            'acos' => (float) $r->sales > 0 ? (float) $r->cost / (float) $r->sales * 100 : null,
            'roas' => (float) $r->cost > 0 ? (float) $r->sales / (float) $r->cost : null,
        ])->all();
    }

    /** @return list<array{type: string, title: string, text: string, id: int|string, value: ?float, bid?: float}> */
    public function suggestions(): array
    {
        $target = $this->targetAcos();
        $out = [];

        foreach (AdSearchTerm::whereNull('handled')->get() as $t) {
            $acos = $t->sales > 0 ? $t->cost / $t->sales * 100 : null;
            if ($t->clicks >= self::MIN_CLICKS && $t->orders === 0 && $t->cost > 0) {
                $out[] = ['type' => 'negate', 'id' => $t->id, 'value' => (float) $t->cost,
                    'title' => "„{$t->search_term}“ ausschließen",
                    'text' => "{$t->clicks} Klicks, ".Format::money($t->cost).' Kosten, kein Verkauf in 30 Tagen.'];
            } elseif ($t->orders >= 2 && $acos !== null && $acos <= $target && ! in_array(mb_strtolower((string) $t->keyword), [mb_strtolower($t->search_term)], true) && $t->match_type !== 'EXACT') {
                $cpc = $t->clicks ? $t->cost / $t->clicks : 0.5;
                $out[] = ['type' => 'promote', 'id' => $t->id, 'value' => (float) $t->sales, 'bid' => round(max(0.2, $cpc * 1.1), 2),
                    'title' => "„{$t->search_term}“ als eigenes Keyword",
                    'text' => "{$t->orders} Verkäufe bei ".Format::percent($acos).' ACoS. Als exaktes Keyword mit Gebot '.Format::money(max(0.2, $cpc * 1.1)).' gezielt bespielen.'];
            }
        }

        $kwStats = AdSearchTerm::whereNotNull('keyword_id')->selectRaw('keyword_id, sum(clicks) as clicks, sum(cost) as cost, sum(sales) as sales, sum(orders) as orders')
            ->groupBy('keyword_id')->get()->keyBy('keyword_id');
        foreach (AdKeyword::where('state', 'ENABLED')->whereNotNull('bid')->get() as $k) {
            $s = $kwStats->get($k->keyword_id);
            if (! $s || $s->clicks < self::MIN_CLICKS) {
                continue;
            }
            $acos = $s->sales > 0 ? $s->cost / $s->sales * 100 : null;
            if ($acos === null || $acos > $target * 1.5) {
                $new = round(max(0.1, $k->bid * 0.85), 2);
                $out[] = ['type' => 'bid_down', 'id' => $k->id, 'value' => (float) $s->cost * 0.15, 'bid' => $new,
                    'title' => "Gebot für „{$k->text}“ senken",
                    'text' => ($acos === null ? 'Kein Verkauf' : 'ACoS '.Format::percent($acos)).' bei '.$s->clicks.' Klicks. Gebot von '.Format::money($k->bid).' auf '.Format::money($new).'.'];
            } elseif ($acos < $target * 0.6 && $s->orders >= 2) {
                $new = round($k->bid * 1.1, 2);
                $out[] = ['type' => 'bid_up', 'id' => $k->id, 'value' => (float) $s->sales * 0.1, 'bid' => $new,
                    'title' => "Gebot für „{$k->text}“ erhöhen",
                    'text' => 'Läuft sehr günstig (ACoS '.Format::percent($acos).'). Mit höherem Gebot mehr Sichtbarkeit: '.Format::money($k->bid).' auf '.Format::money($new).'.'];
            }
        }

        $stats = $this->campaignStats(14);
        foreach (AdCampaign::where('state', 'ENABLED')->get() as $c) {
            $s = $stats[$c->campaign_id] ?? null;
            if (! $s || ! $c->budget) {
                continue;
            }
            $daily = $s['cost'] / max(1, $s['days']);
            if ($s['acos'] !== null && $s['acos'] <= $target && $daily >= (float) $c->budget * 0.9) {
                $new = round((float) $c->budget * 1.25, 0);
                $out[] = ['type' => 'budget_up', 'id' => $c->id, 'value' => ($s['sales'] / 14) * 0.25 * 30, 'bid' => $new,
                    'title' => "Budget für „{$c->name}“ erhöhen",
                    'text' => 'Das Tagesbudget ist fast täglich aufgebraucht, bei '.Format::percent($s['acos']).' ACoS. Von '.Format::money($c->budget, 0).' auf '.Format::money($new, 0).' am Tag.'];
            } elseif ($s['cost'] >= 20 && ($s['acos'] === null || $s['acos'] > $target * 2)) {
                $out[] = ['type' => 'pause', 'id' => $c->id, 'value' => $s['cost'] / 14 * 30,
                    'title' => "„{$c->name}“ pausieren",
                    'text' => ($s['acos'] === null ? 'Kein Werbeumsatz' : 'ACoS '.Format::percent($s['acos'])).' bei '.Format::money($s['cost']).' Kosten in 14 Tagen, deutlich über dem Ziel von '.Format::percent($target).'.'];
            }
        }

        usort($out, fn ($a, $b) => ($b['value'] ?? 0) <=> ($a['value'] ?? 0));

        return $out;
    }
}
