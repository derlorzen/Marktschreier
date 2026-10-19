<?php

namespace App\Livewire\Settings;

use App\Services\BackupService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Sicherung')]
class Backups extends Component
{
    public ?string $notice = null;

    public function backupNow(BackupService $backups): void
    {
        $this->notice = $backups->now();
    }

    public function render(BackupService $backups)
    {
        $list = $backups->list();

        return view('livewire.settings.backups', [
            'backups' => $list,
            'last' => $backups->lastStatus(),
            'stale' => ! $list || $list[0]['time'] < now()->subHours(36)->timestamp,
        ]);
    }
}
