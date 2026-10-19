<?php

namespace App\Services\Alerts;

use App\Mail\AlertMail;
use App\Models\Alert;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Hinweise für alle Benutzer. Gleiche Meldungen (gleicher Schlüssel) werden zusammengefasst,
 * solange sie offen sind. Fehler gehen zusätzlich sofort per Mail raus.
 */
class AlertService
{
    public function raise(string $key, string $type, string $level, string $title, ?string $body = null, ?string $url = null): Alert
    {
        $alert = Alert::open()->where('key', $key)->latest('id')->first();
        if ($alert) {
            $alert->update(['title' => $title, 'body' => $body, 'url' => $url, 'level' => $level]);
            $alert->touch();

            return $alert;
        }
        $alert = Alert::create(compact('key', 'type', 'level', 'title', 'body', 'url'));
        if ($level === 'bad') {
            $this->mailNow($alert);
        }

        return $alert;
    }

    /** Erledigt sich ein Zustand von selbst (z. B. wieder Bestand), verschwindet der Hinweis. */
    public function resolve(string $key): void
    {
        Alert::open()->where('key', $key)->update(['resolved_at' => now()]);
    }

    /** Alle offenen Hinweise eines Typs schließen, deren Schlüssel nicht mehr zutrifft. */
    public function resolveMissing(string $type, array $stillValidKeys): void
    {
        Alert::open()->where('type', $type)->whereNotIn('key', $stillValidKeys)->update(['resolved_at' => now()]);
    }

    private function mailNow(Alert $alert): void
    {
        $users = User::where('active', true)->where('mail_alerts', true)->get();
        if ($users->isEmpty()) {
            return;
        }
        try {
            foreach ($users as $user) {
                Mail::to($user->email)->queue(new AlertMail($alert));
            }
            $alert->update(['mailed_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('Hinweis-Mail nicht versendet: '.$e->getMessage());
        }
    }
}
