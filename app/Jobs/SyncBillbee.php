<?php

namespace App\Jobs;

use App\Models\SyncRun;
use App\Services\Billbee\StockAndCostSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Liest EK und Bestand aus Billbee. Schreibt nie nach Billbee. */
class SyncBillbee implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(public ?int $runId = null)
    {
    }

    public function handle(StockAndCostSync $sync): void
    {
        $sync->run($this->claimRun());
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
