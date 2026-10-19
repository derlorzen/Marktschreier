<?php

namespace App\Services\Ads;

use App\Models\AdCampaign;
use App\Models\AdKeyword;
use App\Models\AdMetric;
use App\Models\AdSearchTerm;
use App\Models\Product;
use App\Services\Marketplaces\MarketplaceException;
use Illuminate\Support\Facades\DB;

class AdsService
{
    public function __construct(private AdsClient $client)
    {
    }

    public function client(): AdsClient
    {
        return $this->client;
    }

    /** @return list<array{id: string, name: string, country: string, type: string}> */
    public function profiles(): array
    {
        return collect($this->client->request('GET', '/v2/profiles', withProfile: false))->map(fn ($p) => [
            'id' => (string) $p['profileId'],
            'name' => $p['accountInfo']['name'] ?? (string) $p['profileId'],
            'country' => $p['countryCode'] ?? '',
            'type' => $p['accountInfo']['type'] ?? '',
        ])->values()->all();
    }

    /** Wählt nach dem Verbinden automatisch das deutsche Verkäuferprofil, falls eindeutig. */
    public function autoSelectProfile(): ?array
    {
        $profiles = collect($this->profiles());
        $de = $profiles->filter(fn ($p) => $p['country'] === 'DE');
        $pick = $de->count() === 1 ? $de->first() : ($profiles->count() === 1 ? $profiles->first() : null);
        $this->client->connection()->putSettings(['profiles' => $profiles->all()] + ($pick ? ['profile_id' => $pick['id']] : []));
        if ($pick) {
            $this->client->connection()->update(['account_name' => $pick['name'].' ('.$pick['country'].')']);
        }

        return $pick;
    }

    /** Kampagnen, Keywords und Berichte der letzten 30 Tage holen. */
    public function sync(int $days = 30): array
    {
        $stats = ['campaigns' => $this->syncCampaigns(), 'keywords' => $this->syncKeywords()];
        $end = now()->subDay()->toDateString();
        $start = now()->subDays($days)->toDateString();

        $rows = $this->report('spCampaigns', ['campaign'], ['date', 'campaignId', 'impressions', 'clicks', 'cost', 'sales7d', 'purchases7d'], 'DAILY', $start, $end);
        DB::transaction(function () use ($rows) {
            foreach ($rows as $r) {
                AdMetric::updateOrCreate(['campaign_id' => (string) $r['campaignId'], 'date' => $r['date']], [
                    'impressions' => (int) ($r['impressions'] ?? 0), 'clicks' => (int) ($r['clicks'] ?? 0),
                    'cost' => (float) ($r['cost'] ?? 0), 'sales' => (float) ($r['sales7d'] ?? 0), 'orders' => (int) ($r['purchases7d'] ?? 0),
                ]);
            }
        });
        $stats['metric_rows'] = count($rows);

        $terms = $this->report('spSearchTerm', ['searchTerm'], ['campaignId', 'adGroupId', 'keywordId', 'keyword', 'matchType', 'searchTerm', 'impressions', 'clicks', 'cost', 'sales7d', 'purchases7d'], 'SUMMARY', $start, $end);
        DB::transaction(function () use ($terms) {
            $handled = AdSearchTerm::whereNotNull('handled')->get()->mapWithKeys(fn ($t) => [$t->campaign_id.'|'.mb_strtolower($t->search_term) => $t->handled]);
            AdSearchTerm::query()->delete();
            foreach ($terms as $r) {
                $key = $r['campaignId'].'|'.mb_strtolower((string) $r['searchTerm']);
                AdSearchTerm::create([
                    'campaign_id' => (string) $r['campaignId'], 'ad_group_id' => isset($r['adGroupId']) ? (string) $r['adGroupId'] : null,
                    'keyword_id' => isset($r['keywordId']) ? (string) $r['keywordId'] : null, 'keyword' => $r['keyword'] ?? null,
                    'match_type' => $r['matchType'] ?? null, 'search_term' => (string) $r['searchTerm'],
                    'impressions' => (int) ($r['impressions'] ?? 0), 'clicks' => (int) ($r['clicks'] ?? 0), 'cost' => (float) ($r['cost'] ?? 0),
                    'sales' => (float) ($r['sales7d'] ?? 0), 'orders' => (int) ($r['purchases7d'] ?? 0), 'handled' => $handled[$key] ?? null,
                ]);
            }
        });
        $stats['search_terms'] = count($terms);
        $this->client->connection()->update(['listings_checked_at' => now()]);

        return $stats;
    }

    private function syncCampaigns(): int
    {
        $seen = [];
        $token = null;
        do {
            $json = $this->client->request('POST', '/sp/campaigns/list', array_filter([
                'stateFilter' => ['include' => ['ENABLED', 'PAUSED']], 'maxResults' => 100, 'nextToken' => $token,
            ]), 'spCampaign');
            foreach ($json['campaigns'] ?? [] as $c) {
                AdCampaign::updateOrCreate(['campaign_id' => (string) $c['campaignId']], [
                    'name' => $c['name'], 'state' => $c['state'], 'targeting' => $c['targetingType'] ?? null,
                    'budget' => $c['budget']['budget'] ?? null,
                ]);
                $seen[] = (string) $c['campaignId'];
            }
            $token = $json['nextToken'] ?? null;
        } while ($token);
        AdCampaign::whereNotIn('campaign_id', $seen)->update(['state' => 'ARCHIVED']);

        return count($seen);
    }

    private function syncKeywords(): int
    {
        $count = 0;
        $token = null;
        do {
            $json = $this->client->request('POST', '/sp/keywords/list', array_filter([
                'stateFilter' => ['include' => ['ENABLED', 'PAUSED']], 'maxResults' => 1000, 'nextToken' => $token,
            ]), 'spKeyword');
            foreach ($json['keywords'] ?? [] as $k) {
                AdKeyword::updateOrCreate(['keyword_id' => (string) $k['keywordId']], [
                    'campaign_id' => (string) $k['campaignId'], 'ad_group_id' => (string) $k['adGroupId'], 'text' => $k['keywordText'],
                    'match_type' => $k['matchType'], 'state' => $k['state'], 'bid' => $k['bid'] ?? null,
                ]);
                $count++;
            }
            $token = $json['nextToken'] ?? null;
        } while ($token);

        return $count;
    }

    /** Bericht anfordern, warten bis er fertig ist, Zeilen zurückgeben. */
    public function report(string $type, array $groupBy, array $columns, string $timeUnit, string $start, string $end): array
    {
        $created = $this->client->request('POST', '/reporting/reports', [
            'name' => 'Marktschreier '.$type.' '.$start.' bis '.$end,
            'startDate' => $start, 'endDate' => $end,
            'configuration' => [
                'adProduct' => 'SPONSORED_PRODUCTS', 'groupBy' => $groupBy, 'columns' => $columns,
                'reportTypeId' => $type, 'timeUnit' => $timeUnit, 'format' => 'GZIP_JSON',
            ],
        ], 'application/vnd.createasyncreportrequest.v3+json');
        $id = $created['reportId'] ?? null;
        if (! $id) {
            throw new MarketplaceException('Amazon hat den Bericht nicht angenommen.');
        }
        // Berichte brauchen meist ein bis wenige Minuten.
        for ($i = 0; $i < 60; $i++) {
            $status = $this->client->request('GET', '/reporting/reports/'.$id);
            if (($status['status'] ?? '') === 'COMPLETED' && ! empty($status['url'])) {
                return $this->client->download($status['url']);
            }
            if (($status['status'] ?? '') === 'FAILURE') {
                throw new MarketplaceException('Bericht fehlgeschlagen: '.($status['failureReason'] ?? 'unbekannt'));
            }
            $this->client->sleep(min(30, 5 + $i * 2));
        }
        throw new MarketplaceException('Der Bericht ist nach mehreren Minuten noch nicht fertig, beim nächsten Lauf erneut.');
    }

    private function check(array $json, string $key): void
    {
        $errors = $json[$key]['error'] ?? [];
        if ($errors) {
            $first = $errors[0]['errors'][0] ?? [];
            throw new MarketplaceException('Amazon Werbung: '.($first['errorValue']['message'] ?? $first['errorType'] ?? json_encode($first)));
        }
    }

    public function setCampaign(AdCampaign $campaign, ?string $state = null, ?float $budget = null): void
    {
        $payload = array_filter(['campaignId' => $campaign->campaign_id, 'state' => $state, 'budget' => $budget !== null ? ['budget' => round($budget, 2), 'budgetType' => 'DAILY'] : null]);
        $json = $this->client->request('PUT', '/sp/campaigns', ['campaigns' => [$payload]], 'spCampaign');
        $this->check($json, 'campaigns');
        $campaign->update(array_filter(['state' => $state, 'budget' => $budget], fn ($v) => $v !== null));
    }

    public function setKeyword(AdKeyword $keyword, ?float $bid = null, ?string $state = null): void
    {
        $payload = array_filter(['keywordId' => $keyword->keyword_id, 'bid' => $bid !== null ? round($bid, 2) : null, 'state' => $state]);
        $json = $this->client->request('PUT', '/sp/keywords', ['keywords' => [$payload]], 'spKeyword');
        $this->check($json, 'keywords');
        $keyword->update(array_filter(['bid' => $bid, 'state' => $state], fn ($v) => $v !== null));
    }

    /** Suchbegriff, der nur kostet, als ausschließendes Keyword (exakt) in der Anzeigengruppe hinterlegen. */
    public function negateSearchTerm(AdSearchTerm $term): void
    {
        $json = $this->client->request('POST', '/sp/negativeKeywords', ['negativeKeywords' => [[
            'campaignId' => $term->campaign_id, 'adGroupId' => $term->ad_group_id, 'keywordText' => $term->search_term,
            'matchType' => 'NEGATIVE_EXACT', 'state' => 'ENABLED',
        ]]], 'spNegativeKeyword');
        $this->check($json, 'negativeKeywords');
        $term->update(['handled' => 'negative']);
    }

    /** Gut laufenden Suchbegriff als exaktes Keyword mit eigenem Gebot übernehmen. */
    public function promoteSearchTerm(AdSearchTerm $term, float $bid): void
    {
        $json = $this->client->request('POST', '/sp/keywords', ['keywords' => [[
            'campaignId' => $term->campaign_id, 'adGroupId' => $term->ad_group_id, 'keywordText' => $term->search_term,
            'matchType' => 'EXACT', 'state' => 'ENABLED', 'bid' => round($bid, 2),
        ]]], 'spKeyword');
        $this->check($json, 'keywords');
        $term->update(['handled' => 'keyword']);
    }

    /**
     * Automatische Kampagne für einen Artikel: Kampagne, Anzeigengruppe, Produktanzeigen je SKU.
     * Amazon findet passende Suchbegriffe selbst, gut für den Start neuer Produkte.
     */
    public function createAutoCampaign(Product $product, float $dailyBudget, float $defaultBid): AdCampaign
    {
        $name = mb_substr('MS Auto | '.$product->title, 0, 128);
        $json = $this->client->request('POST', '/sp/campaigns', ['campaigns' => [[
            'name' => $name, 'targetingType' => 'AUTO', 'state' => 'ENABLED',
            'budget' => ['budgetType' => 'DAILY', 'budget' => round($dailyBudget, 2)],
            'startDate' => now()->timezone('Europe/Berlin')->toDateString(),
            'dynamicBidding' => ['strategy' => 'LEGACY_FOR_SALES'],
        ]]], 'spCampaign');
        $this->check($json, 'campaigns');
        $campaignId = (string) ($json['campaigns']['success'][0]['campaignId'] ?? '');
        if ($campaignId === '') {
            throw new MarketplaceException('Amazon hat keine Kampagnen-ID geliefert.');
        }

        $json = $this->client->request('POST', '/sp/adGroups', ['adGroups' => [[
            'campaignId' => $campaignId, 'name' => 'Alle Varianten', 'state' => 'ENABLED', 'defaultBid' => round($defaultBid, 2),
        ]]], 'spAdGroup');
        $this->check($json, 'adGroups');
        $adGroupId = (string) ($json['adGroups']['success'][0]['adGroupId'] ?? '');

        $skus = $product->variants()->whereNotNull('sku')->pluck('sku');
        $json = $this->client->request('POST', '/sp/productAds', ['productAds' => $skus->map(fn ($sku) => [
            'campaignId' => $campaignId, 'adGroupId' => $adGroupId, 'sku' => $sku, 'state' => 'ENABLED',
        ])->values()->all()], 'spProductAd');
        $this->check($json, 'productAds');

        return AdCampaign::updateOrCreate(['campaign_id' => $campaignId], [
            'name' => $name, 'state' => 'ENABLED', 'targeting' => 'AUTO', 'budget' => $dailyBudget, 'product_id' => $product->id,
        ]);
    }
}
