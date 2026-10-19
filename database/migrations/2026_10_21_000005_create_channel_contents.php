<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Eigene Texte je Kanal. Leer bedeutet: Stammdaten verwenden.
        Schema::create('channel_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('title', 255)->nullable();
            $table->json('bullets')->nullable();
            $table->text('search_terms')->nullable();
            $table->longText('description_html')->nullable();
            $table->string('source')->default('manual');   // manual | ai
            $table->timestamp('pushed_at')->nullable();
            $table->text('push_error')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_contents');
    }
};
