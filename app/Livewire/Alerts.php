<?php

namespace App\Livewire;

use App\Models\Alert;
use App\Services\Alerts\WeeklyReview;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Hinweise')]
class Alerts extends Component
{
    #[Url(except: 'offen')]
    public string $view = 'offen';

    public function markRead(int $id): void
    {
        Alert::findOrFail($id)->markReadBy(auth()->user());
    }

    public function markAllRead(): void
    {
        foreach (Alert::unreadFor(auth()->user())->get() as $alert) {
            $alert->markReadBy(auth()->user());
        }
    }

    public function resolve(int $id): void
    {
        Alert::findOrFail($id)->update(['resolved_at' => now()]);
    }

    public function render()
    {
        $user = auth()->user();
        $alerts = match ($this->view) {
            'erledigt' => Alert::whereNotNull('resolved_at')->latest('resolved_at')->limit(100)->get(),
            default => Alert::open()->orderByRaw("case level when 'bad' then 0 when 'warn' then 1 else 2 end")->latest('updated_at')->get(),
        };
        $readIds = \Illuminate\Support\Facades\DB::table('alert_reads')->where('user_id', $user->id)->pluck('alert_id')->flip();

        return view('livewire.alerts', [
            'alerts' => $alerts,
            'readIds' => $readIds,
            'review' => $this->view === 'woche' ? app(WeeklyReview::class)->build() : null,
            'openCount' => Alert::open()->count(),
        ]);
    }
}
