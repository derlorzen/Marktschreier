<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->date('starts_on')->index();
            $table->string('type')->default('release');      // release | preorder | aktion | messe | sonstiges
            $table->text('notes')->nullable();
            $table->json('product_ids')->nullable();
            $table->unsignedSmallInteger('remind_days')->default(14);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
