<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->string('key')->index();              // zum Zusammenfassen gleicher Meldungen
            $table->string('type');                      // sync_failed, stockout, loss, listing_error, oversell, buybox, release, …
            $table->string('level')->default('info');    // info | warn | bad
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('url')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('mailed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('alert_reads', function (Blueprint $table) {
            $table->foreignId('alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->primary(['alert_id', 'user_id']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('mail_alerts')->default(true);    // sofort bei Fehlern
            $table->boolean('mail_weekly')->default(true);    // Wochenrückblick am Montag
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_reads');
        Schema::dropIfExists('alerts');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['mail_alerts', 'mail_weekly']));
    }
};
