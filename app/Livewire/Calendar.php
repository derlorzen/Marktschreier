<?php

namespace App\Livewire;

use App\Models\CalendarEvent;
use App\Models\Product;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Kalender')]
class Calendar extends Component
{
    public ?int $editId = null;

    public array $form = [];

    public string $productSearch = '';

    public bool $showPast = false;

    public function create(): void
    {
        $this->editId = 0;
        $this->form = ['title' => '', 'starts_on' => now()->addDays(30)->toDateString(), 'type' => 'release', 'notes' => '', 'product_ids' => [], 'remind_days' => 14];
    }

    public function edit(int $id): void
    {
        $e = CalendarEvent::findOrFail($id);
        $this->editId = $id;
        $this->form = ['title' => $e->title, 'starts_on' => $e->starts_on->toDateString(), 'type' => $e->type, 'notes' => (string) $e->notes,
            'product_ids' => $e->product_ids ?? [], 'remind_days' => $e->remind_days];
    }

    public function addProduct(int $id): void
    {
        $this->form['product_ids'] = array_values(array_unique([...($this->form['product_ids'] ?? []), $id]));
        $this->productSearch = '';
    }

    public function removeProduct(int $id): void
    {
        $this->form['product_ids'] = array_values(array_diff($this->form['product_ids'], [$id]));
    }

    public function save(): void
    {
        $this->validate([
            'form.title' => ['required', 'string', 'max:150'],
            'form.starts_on' => ['required', 'date'],
            'form.type' => ['required', 'in:'.implode(',', array_keys(CalendarEvent::TYPES))],
            'form.remind_days' => ['required', 'integer', 'between:0,90'],
            'form.notes' => ['nullable', 'string', 'max:5000'],
        ], ['form.title.required' => 'Bitte einen Titel angeben.', 'form.starts_on.required' => 'Bitte ein Datum angeben.']);
        $data = [
            'title' => trim($this->form['title']), 'starts_on' => $this->form['starts_on'], 'type' => $this->form['type'],
            'notes' => trim($this->form['notes']) ?: null, 'product_ids' => $this->form['product_ids'] ?: null, 'remind_days' => (int) $this->form['remind_days'],
        ];
        $this->editId ? CalendarEvent::findOrFail($this->editId)->update($data) : CalendarEvent::create($data + ['user_id' => auth()->id()]);
        // Erinnerung sofort aktualisieren, nicht erst beim nächsten Prüflauf.
        app(\App\Services\Alerts\AlertChecks::class)->runOnly('calendar');
        $this->reset('editId', 'form');
    }

    public function delete(int $id): void
    {
        CalendarEvent::whereKey($id)->delete();
        app(\App\Services\Alerts\AlertChecks::class)->runOnly('calendar');
    }

    public function render()
    {
        $events = CalendarEvent::query()
            ->when(! $this->showPast, fn ($q) => $q->where('starts_on', '>=', now()->subDays(1)->toDateString()))
            ->orderBy('starts_on')->get();
        $productNames = Product::whereIn('id', $events->pluck('product_ids')->flatten()->filter()->merge($this->form['product_ids'] ?? [])->unique())->pluck('title', 'id');
        $results = trim($this->productSearch) !== ''
            ? Product::search($this->productSearch)->limit(8)->get(['id', 'title'])
            : collect();

        return view('livewire.calendar', [
            'groups' => $events->groupBy(fn ($e) => $e->starts_on->locale('de')->translatedFormat('F Y')),
            'productNames' => $productNames,
            'results' => $results,
        ]);
    }
}
