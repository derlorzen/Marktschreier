<?php

namespace App\Livewire;

use App\Models\AdCampaign;
use App\Models\AdKeyword;
use App\Models\AdMetric;
use App\Models\AdSearchTerm;
use App\Models\Product;
use App\Services\Ads\AdsAdvisor;
use App\Services\Ads\AdsService;
use App\Support\Format;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Werbung')]
class Ads extends Component
{
    #[Url(except: 'vorschlaege')]
    public string $tab = 'vorschlaege';

    /** Artikel für eine neue Auto-Kampagne (aus dem Chancenfinder) */
    #[Url(as: 'neu', except: null)]
    public ?int $newProductId = null;

    public string $newBudget = '5';

    public string $newBid = '0,40';

    public array $budgets = [];

    public ?string $notice = null;

    public ?string $error = null;

    private function run(callable $fn, string $ok): void
    {
        try {
            $fn();
            $this->notice = $ok;
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function apply(string $type, int $id, AdsService $ads): void
    {
        $s = collect(app(AdsAdvisor::class)->suggestions())->first(fn ($x) => $x['type'] === $type && $x['id'] === $id);
        match ($type) {
            'negate' => $this->run(fn () => $ads->negateSearchTerm(AdSearchTerm::findOrFail($id)), 'Suchbegriff ausgeschlossen.'),
            'promote' => $this->run(fn () => $ads->promoteSearchTerm(AdSearchTerm::findOrFail($id), (float) ($s['bid'] ?? 0.4)), 'Als exaktes Keyword angelegt.'),
            'bid_down', 'bid_up' => $this->run(fn () => $ads->setKeyword(AdKeyword::findOrFail($id), (float) $s['bid']), 'Gebot geändert.'),
            'budget_up' => $this->run(fn () => $ads->setCampaign(AdCampaign::findOrFail($id), budget: (float) $s['bid']), 'Budget erhöht.'),
            'pause' => $this->run(fn () => $ads->setCampaign(AdCampaign::findOrFail($id), state: 'PAUSED'), 'Kampagne pausiert.'),
            default => null,
        };
    }

    public function ignoreTerm(int $id): void
    {
        AdSearchTerm::whereKey($id)->update(['handled' => 'ignored']);
    }

    public function toggleCampaign(int $id, AdsService $ads): void
    {
        $c = AdCampaign::findOrFail($id);
        $this->run(fn () => $ads->setCampaign($c, state: $c->state === 'ENABLED' ? 'PAUSED' : 'ENABLED'), $c->state === 'ENABLED' ? 'Kampagne pausiert.' : 'Kampagne läuft wieder.');
    }

    public function saveBudget(int $id, AdsService $ads): void
    {
        $value = Format::parseDecimal($this->budgets[$id] ?? '');
        if ($value === null || (float) $value < 1) {
            $this->error = 'Das Tagesbudget muss mindestens 1 € betragen.';

            return;
        }
        $this->run(fn () => $ads->setCampaign(AdCampaign::findOrFail($id), budget: (float) $value), 'Budget gespeichert.');
    }

    public function createCampaign(AdsService $ads): void
    {
        $product = Product::findOrFail($this->newProductId);
        $budget = (float) Format::parseDecimal($this->newBudget);
        $bid = (float) Format::parseDecimal($this->newBid);
        if ($budget < 1 || $bid < 0.02) {
            $this->error = 'Budget mindestens 1 €, Gebot mindestens 0,02 €.';

            return;
        }
        $this->run(function () use ($ads, $product, $budget, $bid) {
            $ads->createAutoCampaign($product, $budget, $bid);
            $this->newProductId = null;
            $this->tab = 'kampagnen';
        }, "Automatische Kampagne für „{$product->title}“ angelegt. Erste Zahlen gibt es nach ein bis zwei Tagen.");
    }

    public function syncNow(AdsService $ads): void
    {
        $this->run(fn () => $ads->sync(), 'Kampagnen und Berichte aktualisiert.');
    }

    public function render(AdsAdvisor $advisor)
    {
        $connected = $advisor && app(AdsService::class)->client()->connection()->isConnected() && app(AdsService::class)->client()->connection()->setting('profile_id');
        $stats = $advisor->campaignStats(30);
        $totals = collect($stats)->reduce(fn ($c, $s) => [
            'cost' => $c['cost'] + $s['cost'], 'sales' => $c['sales'] + $s['sales'], 'clicks' => $c['clicks'] + $s['clicks'], 'orders' => $c['orders'] + $s['orders'],
        ], ['cost' => 0, 'sales' => 0, 'clicks' => 0, 'orders' => 0]);
        $campaigns = AdCampaign::where('state', '!=', 'ARCHIVED')->orderByRaw("case state when 'ENABLED' then 0 else 1 end")->orderBy('name')->get();
        foreach ($campaigns as $c) {
            $this->budgets[$c->id] ??= Format::decimalForInput($c->budget);
        }
        $daily = AdMetric::where('date', '>=', now()->subDays(30)->toDateString())->selectRaw('date, sum(cost) as cost, sum(sales) as sales')->groupBy('date')->orderBy('date')->get();

        return view('livewire.ads', [
            'connected' => $connected,
            'target' => $advisor->targetAcos(),
            'suggestions' => $this->tab === 'vorschlaege' ? $advisor->suggestions() : [],
            'campaigns' => $campaigns,
            'stats' => $stats,
            'totals' => $totals,
            'daily' => $daily,
            'terms' => $this->tab === 'suchbegriffe' ? AdSearchTerm::orderByDesc('cost')->limit(300)->get() : collect(),
            'campaignNames' => $campaigns->pluck('name', 'campaign_id'),
            'newProduct' => $this->newProductId ? Product::find($this->newProductId) : null,
            'lastSync' => app(AdsService::class)->client()->connection()->listings_checked_at,
        ]);
    }
}
