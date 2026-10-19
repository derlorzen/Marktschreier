<?php

namespace App\Jobs;

use App\Models\SyncRun;
use App\Services\Ads\AdsService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncAds implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 2400;

    public int $tries = 1;

    public function handle(AdsService $ads): void
    {
        $run = SyncRun::start('ads_sync');
        try {
            $run->finish($ads->sync());
        } catch (\Throwable $e) {
            $run->fail($e->getMessage());
            throw $e;
        }
    }
}
