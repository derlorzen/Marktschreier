<?php

namespace Tests\Feature;

use App\Livewire\Alerts;
use App\Mail\AlertMail;
use App\Mail\WeeklyReviewMail;
use App\Models\Alert;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Alerts\AlertService;
use App\Services\Alerts\WeeklyReview;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class AlertsAndBackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_sync_raises_alert_with_mail_and_success_resolves_it(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        User::factory()->create(['mail_alerts' => false]);

        SyncRun::start(SyncRun::TYPE_BILLBEE)->fail('Billbee lehnt den Zugriff ab');
        SyncRun::start(SyncRun::TYPE_BILLBEE)->fail('Billbee lehnt den Zugriff ab');

        $this->assertSame(1, Alert::open()->count());
        Mail::assertQueued(AlertMail::class, 1);
        Mail::assertQueued(AlertMail::class, fn ($m) => $m->hasTo($user->email));

        SyncRun::start(SyncRun::TYPE_BILLBEE)->finish([]);
        $this->assertSame(0, Alert::open()->count());
    }

    public function test_alert_page_marks_read_and_shows_badge(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        app(AlertService::class)->raise('x', 'stockout', 'warn', 'Sleeves ausverkauft', 'Nachbestellen');

        $this->get('/hinweise')->assertOk()->assertSee('Sleeves ausverkauft');
        $this->assertSame(1, Alert::unreadFor($user)->count());
        Livewire::test(Alerts::class)->call('markAllRead');
        $this->assertSame(0, Alert::unreadFor($user)->count());
        Livewire::test(Alerts::class)->set('view', 'woche')->assertSee('Deine Woche');
    }

    public function test_weekly_review_mail_renders(): void
    {
        $review = app(WeeklyReview::class)->build();
        $html = (new WeeklyReviewMail($review))->render();
        $this->assertStringContainsString('Deine Woche', $html);
    }

    public function test_backup_list_download_and_path_safety(): void
    {
        $this->actingAs(User::factory()->create());
        $svc = app(BackupService::class);
        $name = 'marktschreier-20261020-023000.sqlite.gz';
        file_put_contents($svc->dir().'/'.$name, gzencode('test'));

        $this->assertSame($name, $svc->list()[0]['name']);
        $this->assertNull($svc->path('../../.env'));
        $this->get(route('settings.backups.download', $name))->assertOk();
        $this->get('/einstellungen/sicherung')->assertOk()->assertSee($name);
        // In-Memory-Datenbank: keine Datei, die man sichern könnte
        $this->assertStringContainsString('keine Datenbankdatei zum', $svc->now());
        unlink($svc->dir().'/'.$name);
    }
}
