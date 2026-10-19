<?php

namespace App\Jobs;

use App\Models\SyncRun;
use App\Services\Pricing\BuyBoxWatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunPriceWatches implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function handle(BuyBoxWatcher $watcher): void
    {
        $run = SyncRun::start('buybox');
        try {
            $run->finish($watcher->run());
        } catch (\Throwable $e) {
            $run->fail($e->getMessage());
            throw $e;
        }
    }
}
