<?php

namespace App\Jobs;

use App\Models\SyncRun;
use App\Services\Plenty\PlentyImporter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** PlentyONE lesen: "items" (Artikel, Varianten, Preise, Bestand) oder "orders" (Aufträge). */
class SyncPlenty implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 7200;

    public int $tries = 1;

    public int $uniqueFor = 7200;

    public function __construct(public string $what = 'items', public bool $full = false, public ?int $runId = null)
    {
    }

    public function uniqueId(): string
    {
        return $this->what;
    }

    public function handle(PlentyImporter $importer): void
    {
        $run = $this->runId ? SyncRun::find($this->runId) : null;
        $run?->update(['status' => 'running', 'started_at' => now()]);
        $this->what === 'orders' ? $importer->importOrders($run) : $importer->importItems($this->full, $run);
    }

    public function failed(?\Throwable $e): void
    {
        $run = $this->runId ? SyncRun::find($this->runId) : null;
        if ($run && $run->status !== 'failed') {
            $run->fail($e?->getMessage() ?? 'Abgebrochen');
        }
    }
}
