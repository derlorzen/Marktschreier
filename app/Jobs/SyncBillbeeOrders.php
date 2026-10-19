<?php

namespace App\Jobs;

use App\Models\SyncRun;
use App\Services\Billbee\OrderSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Liest Bestellungen aus Billbee (ohne Kundendaten). */
class SyncBillbeeOrders implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(public ?int $runId = null)
    {
    }

    public function handle(OrderSync $sync): void
    {
        $run = $this->runId ? SyncRun::find($this->runId) : null;
        $run?->update(['status' => 'running', 'started_at' => now()]);
        $sync->run($run);
    }

    public function failed(?\Throwable $e): void
    {
        $run = $this->runId ? SyncRun::find($this->runId) : null;
        if ($run && $run->status !== 'failed') {
            $run->fail($e?->getMessage() ?? 'Abgebrochen', $run->stats ?? []);
        }
    }
}
