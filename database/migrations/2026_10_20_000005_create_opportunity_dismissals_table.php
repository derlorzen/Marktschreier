<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ausgeblendete Chancen. Ohne "until" dauerhaft, sonst kommen sie danach wieder, falls noch zutreffend.
        Schema::create('opportunity_dismissals', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->timestamp('until')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_dismissals');
    }
};
