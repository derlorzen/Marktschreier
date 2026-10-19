<?php

namespace App\Livewire;

use App\Services\Stock\StockOverview;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Bestand')]
class Stock extends Component
{
    #[Url(except: '')]
    public string $filter = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function render(StockOverview $overview)
    {
        $rows = $overview->rows();
        $all = $rows;
        $term = mb_strtolower(trim($this->search));
        if ($term !== '') {
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower($r['variant']->product->title.' '.$r['variant']->sku), $term));
        }
        $rows = match ($this->filter) {
            'warn' => $rows->filter(fn ($r) => $r['warnings']),
            'empty' => $rows->filter(fn ($r) => $r['own'] !== null && $r['own'] <= 0),
            'fba' => $rows->filter(fn ($r) => $r['fba']),
            default => $rows,
        };

        return view('livewire.stock', [
            'rows' => $rows->sortBy(fn ($r) => [$r['warnings'] ? 0 : 1, $r['variant']->product->title])->values(),
            'warnCount' => $all->filter(fn ($r) => $r['warnings'])->count(),
            'emptyCount' => $all->filter(fn ($r) => $r['own'] !== null && $r['own'] <= 0)->count(),
            'fbaCount' => $all->filter(fn ($r) => $r['fba'])->count(),
            'hasEbay' => $all->contains(fn ($r) => isset($r['shown']['ebay'])),
            'hasAmazon' => $all->contains(fn ($r) => isset($r['shown']['amazon'])),
        ]);
    }
}
