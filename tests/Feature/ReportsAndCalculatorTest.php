<?php

namespace Tests\Feature;

use App\Livewire\Calculator;
use App\Mail\MonthlyReportMail;
use App\Models\AdMetric;
use App\Models\Channel;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Reports\MonthlyReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class ReportsAndCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => User::STAFF]));
        $shop = Channel::where('key', 'shopify')->first();
        foreach ([['2026-09-10 12:00', 119.00, 3], ['2026-09-20 12:00', 59.50, 3], ['2026-08-15 12:00', 50.00, 3], ['2026-09-21 12:00', 1000, 8]] as $i => [$at, $gross, $state]) {
            $o = Order::create(['billbee_id' => 100 + $i, 'channel_id' => $shop->id, 'state' => $state, 'ordered_at' => $at, 'total_gross' => $gross]);
            OrderItem::create(['order_id' => $o->id, 'quantity' => 1, 'total_gross' => $gross, 'tax_amount' => round($gross - $gross / 1.19, 2), 'cost_net' => 10]);
        }
        AdMetric::create(['campaign_id' => 'c1', 'date' => '2026-09-05', 'cost' => 20, 'sales' => 100, 'clicks' => 40, 'orders' => 3]);
        AdMetric::create(['campaign_id' => 'c1', 'date' => '2026-10-01', 'cost' => 99, 'sales' => 0]);
    }

    public function test_monthly_report_numbers_exclude_cancelled_and_other_months(): void
    {
        $r = app(MonthlyReport::class)->build(2026, 9);
        $this->assertSame(2, $r['total']['orders']);
        $this->assertEqualsWithDelta(178.50, $r['total']['gross'], 0.01);
        $this->assertEqualsWithDelta(150.00, $r['total']['net'], 0.02);
        $this->assertEqualsWithDelta(20.0, $r['total']['ad_cost'], 0.001);
        $this->assertEqualsWithDelta($r['total']['profit'] - 20, $r['total']['after_ads'], 0.001);
        $this->assertEqualsWithDelta(50.0, $r['previous']['gross'], 0.01);
        $this->assertSame('Monatsabschluss September 2026', $r['title']);
    }

    public function test_pdf_download_and_page(): void
    {
        $res = $this->get(route('reports.monthly-pdf', ['year' => 2026, 'month' => 9]));
        $res->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->get('/berichte?m=2026-09')->assertOk()->assertSee('Umsatz netto');
        $this->get('/berichte?view=woche')->assertOk()->assertSee('Deine Woche');
        $this->get('/berichte/monatsabschluss/2026/13.pdf')->assertNotFound();
    }

    public function test_monthly_mail_has_pdf_attachment(): void
    {
        $mail = new MonthlyReportMail(2026, 9, 'Monatsabschluss September 2026');
        $att = $mail->attachments()[0];
        $this->assertSame('monatsabschluss-2026-09.pdf', $att->as);
        $this->assertSame('application/pdf', $att->mime);
        $data = $att->attachWith(fn () => null, fn ($data) => $data());
        $this->assertStringStartsWith('%PDF', $data);
        $this->assertStringContainsString('Monatsabschluss September 2026', $mail->render());
    }

    public function test_calculator_compares_scenarios(): void
    {
        $shop = Channel::where('key', 'shopify')->first();
        Livewire::test(Calculator::class)
            ->set('channelId', $shop->id)
            ->set('now', ['price' => '19,99', 'cost' => '6,00', 'units' => '30', 'ads' => '0'])
            ->set('plan', ['price' => '17,99', 'cost' => '6,00', 'units' => '40', 'ads' => '50'])
            ->assertSee('Gewinn im Monat nach Werbung')
            ->assertSee('Stück')
            ->assertSee('Werbekosten-Grenze');
        $this->get('/rechner')->assertOk();
    }
}
