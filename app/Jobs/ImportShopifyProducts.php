<?php

namespace App\Jobs;

use App\Models\SyncRun;
use App\Services\Shopify\ProductImporter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Holt Artikel aus Shopify. Ohne $full nur die seit dem letzten erfolgreichen Import geänderten.
 */
class ImportShopifyProducts implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(public bool $full = false, public ?int $runId = null)
    {
    }

    public function handle(ProductImporter $importer): void
    {
        $since = null;
        if (! $this->full) {
            // Kleine Überlappung, damit nichts zwischen zwei Läufen verloren geht.
            $since = SyncRun::lastSuccessful(SyncRun::TYPE_SHOPIFY_IMPORT)?->started_at?->copy()->subMinutes(5);
        }
        $run = $this->claimRun();
        $importer->run($since, $run);
    }

    private function claimRun(): ?SyncRun
    {
        $run = $this->runId ? SyncRun::find($this->runId) : null;
        $run?->update(['status' => 'running', 'started_at' => now()]);

        return $run;
    }

    public function failed(?\Throwable $e): void
    {
        $run = $this->runId ? SyncRun::find($this->runId) : null;
        if ($run && $run->status !== 'failed') {
            $run->fail($e?->getMessage() ?? 'Abgebrochen', $run->stats ?? []);
        }
    }
}
