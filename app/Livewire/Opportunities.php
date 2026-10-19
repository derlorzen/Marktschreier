<?php

namespace App\Livewire;

use App\Models\OpportunityDismissal;
use App\Services\Opportunities\Opportunity;
use App\Services\Opportunities\OpportunityFinder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Chancen')]
class Opportunities extends Component
{
    #[Url(except: 'free')]
    public string $kind = 'free';

    #[Url(except: '')]
    public string $type = '';

    public bool $showDismissed = false;

    public ?string $notice = null;

    public function updatedKind(): void
    {
        $this->type = '';
    }

    /** Erledigt: 30 Tage ausblenden, danach wieder zeigen, falls noch zutreffend. */
    public function done(string $key): void
    {
        OpportunityDismissal::updateOrCreate(['key' => $key], ['until' => now()->addDays(30), 'reason' => 'erledigt', 'user_id' => auth()->id()]);
        $this->notice = 'Als erledigt markiert. Trifft die Chance in 30 Tagen noch zu, taucht sie wieder auf.';
    }

    /** Nicht relevant: dauerhaft ausblenden. */
    public function ignore(string $key): void
    {
        OpportunityDismissal::updateOrCreate(['key' => $key], ['until' => null, 'reason' => 'nicht relevant', 'user_id' => auth()->id()]);
        $this->notice = 'Dauerhaft ausgeblendet.';
    }

    public function restore(string $key): void
    {
        OpportunityDismissal::where('key', $key)->delete();
        $this->notice = 'Wieder eingeblendet.';
    }

    public function render(OpportunityFinder $finder)
    {
        $all = $finder->all(includeDismissed: $this->showDismissed);
        $dismissed = $this->showDismissed ? OpportunityDismissal::activeKeys() : [];
        $ofKind = $all->where('kind', $this->kind);
        $list = $this->type !== '' ? $ofKind->where('type', $this->type) : $ofKind;

        return view('livewire.opportunities', [
            'list' => $list->take(200),
            'total' => $list->count(),
            'counts' => ['free' => $all->where('kind', Opportunity::FREE)->count(), 'ads' => $all->where('kind', Opportunity::ADS)->count()],
            'types' => $ofKind->countBy('type')->sortDesc(),
            'value' => $list->sum(fn (Opportunity $o) => $o->monthlyValue ?? 0),
            'dismissed' => $dismissed,
        ]);
    }
}
