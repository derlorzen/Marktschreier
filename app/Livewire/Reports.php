<?php

namespace App\Livewire;

use App\Services\Alerts\WeeklyReview;
use App\Services\Reports\MonthlyReport;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Berichte')]
class Reports extends Component
{
    #[Url(except: 'monat')]
    public string $view = 'monat';

    /** Monat im Format JJJJ-MM */
    #[Url(as: 'm', except: '')]
    public string $month = '';

    public function mount(): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month)) {
            $this->month = now()->timezone('Europe/Berlin')->subMonthNoOverflow()->format('Y-m');
        }
    }

    public function render(MonthlyReport $monthly, WeeklyReview $weekly)
    {
        [$y, $m] = array_map('intval', explode('-', $this->month));
        $months = collect(range(0, 23))->map(fn ($i) => now()->timezone('Europe/Berlin')->startOfMonth()->subMonths($i))
            ->mapWithKeys(fn ($d) => [$d->format('Y-m') => $d->locale('de')->translatedFormat('F Y').($d->isCurrentMonth() ? ' (laufend)' : '')]);

        return view('livewire.reports', [
            'report' => $this->view === 'monat' ? $monthly->build($y, $m) : null,
            'week' => $this->view === 'woche' ? $weekly->build() : null,
            'months' => $months,
            'y' => $y, 'm' => $m,
        ]);
    }
}
