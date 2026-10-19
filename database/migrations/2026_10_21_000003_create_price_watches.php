<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Buy-Box- und Preiswächter je Variante auf Amazon
        Schema::create('price_watches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('active')->default(true);
            $table->string('strategy')->default('beat');      // beat | match | watch
            $table->decimal('min_price', 12, 2);
            $table->decimal('max_price', 12, 2)->nullable();
            $table->boolean('auto')->default(false);           // Vorschlag selbst übernehmen
            $table->unsignedSmallInteger('max_daily_change')->default(20); // in % je Tag
            $table->json('last_result')->nullable();
            $table->decimal('suggested_price', 12, 2)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('price_watch_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_watch_id')->constrained()->cascadeOnDelete();
            $table->decimal('my_price', 12, 2)->nullable();
            $table->decimal('buybox_price', 12, 2)->nullable();
            $table->decimal('lowest_competitor', 12, 2)->nullable();
            $table->boolean('won')->default(false);
            $table->decimal('new_price', 12, 2)->nullable();
            $table->string('action')->default('checked');      // checked | suggested | applied | blocked
            $table->string('note')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_watch_events');
        Schema::dropIfExists('price_watches');
    }
};
