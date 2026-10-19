<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('campaign_id')->unique();
            $table->string('name');
            $table->string('state');                    // ENABLED | PAUSED | ARCHIVED
            $table->string('targeting')->nullable();    // AUTO | MANUAL
            $table->decimal('budget', 10, 2)->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete(); // von Marktschreier angelegt
            $table->timestamps();
        });
        Schema::create('ad_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('campaign_id')->index();
            $table->date('date');
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->decimal('cost', 10, 2)->default(0);
            $table->decimal('sales', 10, 2)->default(0);
            $table->unsignedInteger('orders')->default(0);
            $table->unique(['campaign_id', 'date']);
        });
        Schema::create('ad_keywords', function (Blueprint $table) {
            $table->id();
            $table->string('keyword_id')->unique();
            $table->string('campaign_id')->index();
            $table->string('ad_group_id');
            $table->string('text');
            $table->string('match_type');
            $table->string('state');
            $table->decimal('bid', 8, 2)->nullable();
            $table->timestamps();
        });
        // Suchbegriffe der letzten 30 Tage, bei jedem Lesen ersetzt
        Schema::create('ad_search_terms', function (Blueprint $table) {
            $table->id();
            $table->string('campaign_id')->index();
            $table->string('ad_group_id')->nullable();
            $table->string('keyword_id')->nullable();
            $table->string('keyword')->nullable();
            $table->string('match_type')->nullable();
            $table->string('search_term');
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->decimal('cost', 10, 2)->default(0);
            $table->decimal('sales', 10, 2)->default(0);
            $table->unsignedInteger('orders')->default(0);
            $table->string('handled')->nullable();      // negative | keyword | ignored
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['ad_search_terms', 'ad_keywords', 'ad_metrics', 'ad_campaigns'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
