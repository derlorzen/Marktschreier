<?php

namespace App\Jobs;

use App\Models\SyncRun;
use App\Services\Marketplaces\MarketplaceManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Angebote eines Marktplatzes lesen ("listings") oder offene Preise übertragen ("prices").
 */
class SyncMarketplace implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(public string $provider, public string $action = 'listings', public ?int $runId = null, public ?array $productIds = null)
    {
    }

    public function uniqueId(): string
    {
        return $this->provider.':'.$this->action.':'.md5(json_encode($this->productIds));
    }

    public function handle(MarketplaceManager $manager): void
    {
        $run = $this->runId ? SyncRun::find($this->runId) : SyncRun::start($this->action.'_'.$this->provider);
        $run->update(['status' => 'running', 'started_at' => now()]);
        try {
            $stats = $this->action === 'prices'
                ? $manager->pushPrices($this->provider, $this->productIds)
                : $manager->syncListings($this->provider);
        } catch (\Throwable $e) {
            $run->fail($e->getMessage());
            throw $e;
        }
        $run->finish($stats, ($stats['failed'] ?? 0) ? $stats['failed'].' Preise konnten nicht übertragen werden, Details im Artikel unter Marktplätze.' : null);
    }

    public function failed(?\Throwable $e): void
    {
        $run = $this->runId ? SyncRun::find($this->runId) : null;
        if ($run && $run->status !== 'failed') {
            $run->fail($e?->getMessage() ?? 'Abgebrochen');
        }
    }
}
