<?php

namespace Tests\Feature;

use App\Livewire\Ads;
use App\Models\AdCampaign;
use App\Models\AdKeyword;
use App\Models\AdMetric;
use App\Models\AdSearchTerm;
use App\Models\MarketplaceConnection;
use App\Models\Product;
use App\Models\User;
use App\Services\Ads\AdsAdvisor;
use App\Services\Ads\AdsClient;
use App\Services\Ads\AdsService;
use App\Services\Shopify\ProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\FakesApis;
use Tests\TestCase;

class AdsTest extends TestCase
{
    use FakesApis, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['marktschreier.amazon_ads.client_id' => 'ads-cid', 'marktschreier.amazon_ads.client_secret' => 'ads-secret']);
        $this->bindApiClients();
        $this->app->bind(AdsClient::class, fn () => new AdsClient(fn () => null));
        $c = MarketplaceConnection::for('amazon_ads');
        $c->storeSecrets(['refresh_token' => 'rt', 'access_token' => 'at', 'access_expires_at' => time() + 3600]);
        $c->update(['status' => 'connected']);
        $c->putSettings(['profile_id' => '123', 'target_acos' => 25]);
        $this->actingAs(User::factory()->create());
    }

    public function test_sync_reads_campaigns_keywords_and_reports(): void
    {
        $campaignReport = gzencode(json_encode([['date' => now()->subDays(2)->toDateString(), 'campaignId' => 11, 'impressions' => 1000, 'clicks' => 40, 'cost' => 12.5, 'sales7d' => 80, 'purchases7d' => 6]]));
        $termReport = gzencode(json_encode([
            ['campaignId' => 11, 'adGroupId' => 21, 'keywordId' => 31, 'keyword' => 'sleeves', 'matchType' => 'BROAD', 'searchTerm' => 'sleeves matt schwarz', 'impressions' => 300, 'clicks' => 12, 'cost' => 6.4, 'sales7d' => 0, 'purchases7d' => 0],
            ['campaignId' => 11, 'adGroupId' => 21, 'keywordId' => 31, 'keyword' => 'sleeves', 'matchType' => 'BROAD', 'searchTerm' => 'kartenhüllen 100', 'impressions' => 200, 'clicks' => 8, 'cost' => 3.2, 'sales7d' => 39.6, 'purchases7d' => 4],
        ]));
        Http::fake([
            'advertising-api-eu.amazon.com/sp/campaigns/list' => Http::response(['campaigns' => [['campaignId' => '11', 'name' => 'Sleeves Auto', 'state' => 'ENABLED', 'targetingType' => 'AUTO', 'budget' => ['budget' => 5, 'budgetType' => 'DAILY']]]]),
            'advertising-api-eu.amazon.com/sp/keywords/list' => Http::response(['keywords' => [['keywordId' => '31', 'campaignId' => '11', 'adGroupId' => '21', 'keywordText' => 'sleeves', 'matchType' => 'BROAD', 'state' => 'ENABLED', 'bid' => 0.6]]]),
            'advertising-api-eu.amazon.com/reporting/reports' => Http::sequence()->push(['reportId' => 'r1', 'status' => 'PENDING'])->push(['reportId' => 'r2', 'status' => 'PENDING']),
            'advertising-api-eu.amazon.com/reporting/reports/r1' => Http::sequence()->push(['status' => 'PROCESSING'])->push(['status' => 'COMPLETED', 'url' => 'https://offline-report-storage.s3.amazonaws.com/r1.json.gz']),
            'advertising-api-eu.amazon.com/reporting/reports/r2' => Http::response(['status' => 'COMPLETED', 'url' => 'https://offline-report-storage.s3.amazonaws.com/r2.json.gz']),
            'offline-report-storage.s3.amazonaws.com/r1.json.gz' => Http::response($campaignReport),
            'offline-report-storage.s3.amazonaws.com/r2.json.gz' => Http::response($termReport),
        ]);

        $stats = app(AdsService::class)->sync();

        $this->assertSame(['campaigns' => 1, 'keywords' => 1, 'metric_rows' => 1, 'search_terms' => 2], $stats);
        $this->assertSame('80.00', AdMetric::sole()->sales);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sp/campaigns/list') && $r->hasHeader('Amazon-Advertising-API-Scope', '123')
            && $r->hasHeader('Content-Type', 'application/vnd.spCampaign.v3+json') && $r->hasHeader('Amazon-Advertising-API-ClientId', 'ads-cid'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/reporting/reports') && $r['configuration']['reportTypeId'] === 'spSearchTerm' && $r['configuration']['format'] === 'GZIP_JSON');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 's3.amazonaws.com') && ! $r->hasHeader('Authorization'));

        // Vorschläge: teurer Suchbegriff ausschließen, guter als Keyword
        $types = collect(app(AdsAdvisor::class)->suggestions())->pluck('type')->all();
        $this->assertContains('negate', $types);
        $this->assertContains('promote', $types);
    }

    public function test_applying_suggestions_calls_the_api(): void
    {
        AdCampaign::create(['campaign_id' => '11', 'name' => 'Sleeves Auto', 'state' => 'ENABLED', 'budget' => 5]);
        $t = AdSearchTerm::create(['campaign_id' => '11', 'ad_group_id' => '21', 'search_term' => 'gratis sleeves', 'clicks' => 15, 'cost' => 7.5, 'sales' => 0, 'orders' => 0]);
        Http::fake(['advertising-api-eu.amazon.com/sp/negativeKeywords' => Http::response(['negativeKeywords' => ['success' => [['negativeKeywordId' => '9']], 'error' => []]])]);

        Livewire::test(Ads::class)->call('apply', 'negate', $t->id)->assertSet('error', null);

        $this->assertSame('negative', $t->fresh()->handled);
        Http::assertSent(fn (Request $r) => $r['negativeKeywords'][0]['matchType'] === 'NEGATIVE_EXACT' && $r['negativeKeywords'][0]['keywordText'] === 'gratis sleeves');
    }

    public function test_budget_change_and_api_errors(): void
    {
        $c = AdCampaign::create(['campaign_id' => '11', 'name' => 'X', 'state' => 'ENABLED', 'budget' => 5]);
        Http::fake(['advertising-api-eu.amazon.com/sp/campaigns' => Http::sequence()
            ->push(['campaigns' => ['success' => [['campaignId' => '11']], 'error' => []]])
            ->push(['campaigns' => ['success' => [], 'error' => [['index' => 0, 'errors' => [['errorType' => 'budgetTooLow', 'errorValue' => ['message' => 'Budget zu niedrig']]]]]]])]);

        Livewire::test(Ads::class)->set('budgets.'.$c->id, '8,50')->call('saveBudget', $c->id)->assertSet('error', null);
        $this->assertSame('8.50', $c->fresh()->budget);

        Livewire::test(Ads::class)->set('budgets.'.$c->id, '2')->call('saveBudget', $c->id)->assertSet('error', 'Amazon Werbung: Budget zu niedrig');
    }

    public function test_create_auto_campaign_for_product(): void
    {
        $this->queueShopify($this->productsPage([$this->shopifyNode()]));
        app(ProductImporter::class)->run();
        $p = Product::sole();
        Http::fake([
            'advertising-api-eu.amazon.com/sp/campaigns' => Http::response(['campaigns' => ['success' => [['campaignId' => '501']], 'error' => []]]),
            'advertising-api-eu.amazon.com/sp/adGroups' => Http::response(['adGroups' => ['success' => [['adGroupId' => '601']], 'error' => []]]),
            'advertising-api-eu.amazon.com/sp/productAds' => Http::response(['productAds' => ['success' => [['adId' => '701']], 'error' => []]]),
        ]);

        Livewire::test(Ads::class, ['newProductId' => $p->id])->set('newProductId', $p->id)->set('newBudget', '7')->set('newBid', '0,45')->call('createCampaign')->assertSet('error', null);

        $this->assertSame($p->id, AdCampaign::where('campaign_id', '501')->value('product_id'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sp/campaigns') && $r['campaigns'][0]['targetingType'] === 'AUTO' && $r['campaigns'][0]['budget']['budget'] == 7);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sp/productAds') && count($r['productAds']) === 2 && $r['productAds'][0]['adGroupId'] === '601');
    }

    public function test_pages_render(): void
    {
        AdKeyword::create(['keyword_id' => '31', 'campaign_id' => '11', 'ad_group_id' => '21', 'text' => 'sleeves', 'match_type' => 'EXACT', 'state' => 'ENABLED', 'bid' => 0.5]);
        foreach (['vorschlaege', 'kampagnen', 'suchbegriffe', 'verlauf'] as $tab) {
            $this->get('/werbung?tab='.$tab)->assertOk();
        }
        $this->get('/einstellungen/amazon-werbung')->assertOk()->assertSee('Ziel-ACoS');
    }
}
