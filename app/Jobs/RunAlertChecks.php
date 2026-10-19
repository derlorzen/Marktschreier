<?php

namespace App\Jobs;

use App\Services\Alerts\AlertChecks;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunAlertChecks implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 900;

    public function handle(AlertChecks $checks): void
    {
        $checks->run();
    }
}
