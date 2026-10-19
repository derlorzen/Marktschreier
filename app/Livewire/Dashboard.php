<?php

namespace App\Livewire;

use App\Models\Channel;
use App\Models\Order;
use App\Models\Product;
use App\Models\SyncRun;
use App\Models\Variant;
use App\Services\Sales\SalesStats;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Start')]
class Dashboard extends Component
{
    #[Url(except: 30)]
    public int $days = 30;

    public function updatedDays(): void
    {
        if (! in_array($this->days, [7, 30, 90, 365], true)) {
            $this->days = 30;
        }
    }

    public function render(SalesStats $stats)
    {
        $now = $stats->period($this->days);
        $before = $stats->period($this->days, now()->subDays($this->days));
        $channels = Channel::orderBy('position')->get()->keyBy('id');

        $channelRows = collect($now['channels'])->map(fn ($agg, $id) => $agg + [
            'name' => $id === 'none' ? 'Ohne Kanal' : ($channels->get($id)?->name ?? 'Unbekannt'),
            'prev' => $before['channels'][$id]['revenue_gross'] ?? 0,
        ])->sortByDesc('revenue_gross')->values();

        $variantIds = collect($now['variants'])->sortByDesc('profit')->take(10)->keys();
        $variants = Variant::with('product:id,title')->whereIn('id', $variantIds)->get()->keyBy('id');
        $top = $variantIds->map(fn ($id) => $now['variants'][$id] + ['variant' => $variants->get($id)])->filter(fn ($r) => $r['variant'])->values();

        $hour = (int) now()->timezone('Europe/Berlin')->format('G');

        return view('livewire.dashboard', [
            'now' => $now['total'],
            'before' => $before['total'],
            'channelRows' => $channelRows,
            'maxChannel' => max(1, $channelRows->max('revenue_gross') ?? 1),
            'daily' => $stats->daily(min($this->days, 90)),
            'top' => $top,
            'hasOrders' => Order::exists(),
            'greeting' => $hour < 11 ? 'Guten Morgen' : ($hour < 18 ? 'Hallo' : 'Guten Abend'),
            'pending' => Product::whereIn('push_status', ['pending', 'error', 'conflict'])->count(),
            'lastOrders' => SyncRun::lastSuccessful(SyncRun::TYPE_BILLBEE_ORDERS),
            'chances' => app(\App\Services\Opportunities\OpportunityFinder::class)->all()->take(3),
        ]);
    }
}
