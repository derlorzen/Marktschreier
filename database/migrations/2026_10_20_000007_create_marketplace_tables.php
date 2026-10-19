<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Verbindung je Marktplatz. Tokens liegen verschlüsselt in "credentials".
        Schema::create('marketplace_connections', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique();          // ebay | amazon
            $table->text('credentials')->nullable();        // verschlüsseltes JSON
            $table->json('settings')->nullable();
            $table->string('account_name')->nullable();
            $table->string('status')->default('disconnected'); // disconnected | connected | error
            $table->text('last_error')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('listings_checked_at')->nullable();
            $table->timestamps();
        });

        // Angebot einer Variante auf einem Marktplatz, so wie es dort zuletzt gesehen wurde.
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable();      // eBay ItemID, Amazon ASIN
            $table->string('sku')->nullable();
            $table->string('status')->default('unknown');   // active | inactive | pending | error | unknown
            $table->decimal('remote_price', 12, 2)->nullable();
            $table->decimal('pushed_price', 12, 2)->nullable();
            $table->text('last_error')->nullable();
            $table->boolean('created_by_us')->default(false);
            $table->json('data')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('price_pushed_at')->nullable();
            $table->timestamps();
            $table->unique(['variant_id', 'channel_id']);
            $table->index(['channel_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listings');
        Schema::dropIfExists('marketplace_connections');
    }
};
