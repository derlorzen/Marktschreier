<?php

namespace Tests\Feature;

use App\Livewire\Calendar;
use App\Models\Alert;
use App\Models\CalendarEvent;
use App\Models\Product;
use App\Models\User;
use App\Services\Alerts\AlertChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => User::STAFF]));
    }

    public function test_create_edit_and_link_products(): void
    {
        $p = Product::create(['shopify_id' => 'gid://shopify/Product/9', 'title' => 'Sleeves Drachen', 'status' => 'ACTIVE', 'push_status' => 'synced']);

        Livewire::test(Calendar::class)
            ->call('create')
            ->set('form.title', 'Neues Set')->set('form.starts_on', now()->addDays(10)->toDateString())
            ->set('productSearch', 'Drachen')->assertSee('Sleeves Drachen')
            ->call('addProduct', $p->id)
            ->call('save')->assertHasNoErrors()
            ->assertSee('Neues Set')->assertSee('in 10 Tagen');

        $e = CalendarEvent::sole();
        $this->assertSame([$p->id], $e->product_ids);

        Livewire::test(Calendar::class)->call('edit', $e->id)->set('form.title', 'Neues Set, Vorverkauf')->call('removeProduct', $p->id)->call('save');
        $this->assertSame('Neues Set, Vorverkauf', $e->fresh()->title);
        $this->assertNull($e->fresh()->product_ids);
    }

    public function test_reminder_appears_within_window_and_disappears_after_delete(): void
    {
        $soon = CalendarEvent::create(['title' => 'Bald', 'starts_on' => now()->addDays(5)->toDateString(), 'type' => 'release', 'remind_days' => 14, 'product_ids' => [1, 2]]);
        CalendarEvent::create(['title' => 'Später', 'starts_on' => now()->addDays(40)->toDateString(), 'type' => 'release', 'remind_days' => 14]);

        app(AlertChecks::class)->runOnly('calendar');
        $this->assertSame(['In 5 Tagen: Bald'], Alert::open()->where('type', 'calendar')->pluck('title')->all());
        $this->assertStringContainsString('2 passende Artikel', Alert::open()->first()->body);

        Livewire::test(Calendar::class)->call('delete', $soon->id);
        $this->assertSame(0, Alert::open()->where('type', 'calendar')->count());
    }

    public function test_validation_and_page(): void
    {
        Livewire::test(Calendar::class)->call('create')->set('form.title', '')->call('save')->assertHasErrors('form.title');
        $this->get('/kalender')->assertOk()->assertSee('Termin hinzufügen');
    }
}
