<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Verkaufskanäle mit ihren Kosten je Verkauf. Der Shopify-Preis steht an der Variante selbst.
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // shopify, amazon, ebay, tiktok
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->decimal('fee_percent', 6, 3)->default(0);   // Gebühr in % vom Bruttopreis
            $table->decimal('fee_fixed', 8, 2)->default(0);     // feste Gebühr je Verkauf
            $table->decimal('shipping_cost', 8, 2)->default(0); // Versand und Verpackung je Verkauf, netto
            $table->string('billbee_platforms')->nullable();    // Kommagetrennte Plattformnamen aus Billbee
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('price_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('min_profit', 8, 2)->default(0);    // Mindestgewinn je Verkauf in Euro
            $table->timestamps();
        });

        Schema::create('price_rule_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->decimal('target_margin', 6, 2)->default(30); // Gewinn in % vom Nettopreis
            $table->string('ending', 2)->default('99');          // 99, 95, 90, 00
            $table->unique(['price_rule_id', 'channel_id']);
        });

        // Preis je Variante und Kanal (außer Shopify). Ohne Eintrag gilt der Shopify-Preis.
        Schema::create('channel_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 12, 2);
            $table->string('source')->default('manual');         // manual | rule
            $table->foreignId('price_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['variant_id', 'channel_id']);
        });

        $now = now();
        DB::table('channels')->insert([
            ['key' => 'shopify', 'name' => 'Onlineshop', 'active' => true, 'fee_percent' => 2.5, 'fee_fixed' => 0.25, 'shipping_cost' => 0, 'billbee_platforms' => 'shopify', 'position' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'amazon', 'name' => 'Amazon', 'active' => true, 'fee_percent' => 15, 'fee_fixed' => 0, 'shipping_cost' => 0, 'billbee_platforms' => 'amazon', 'position' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'ebay', 'name' => 'eBay', 'active' => true, 'fee_percent' => 11, 'fee_fixed' => 0.35, 'shipping_cost' => 0, 'billbee_platforms' => 'ebay', 'position' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'tiktok', 'name' => 'TikTok Shop', 'active' => false, 'fee_percent' => 9, 'fee_fixed' => 0, 'shipping_cost' => 0, 'billbee_platforms' => 'tiktok', 'position' => 4, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $ruleId = DB::table('price_rules')->insertGetId([
            'name' => 'Standard', 'description' => 'Ausgangspunkt, bitte an die eigenen Zahlen anpassen.',
            'min_profit' => 1.00, 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach (DB::table('channels')->pluck('id') as $channelId) {
            DB::table('price_rule_lines')->insert(['price_rule_id' => $ruleId, 'channel_id' => $channelId, 'target_margin' => 30, 'ending' => '99']);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_prices');
        Schema::dropIfExists('price_rule_lines');
        Schema::dropIfExists('price_rules');
        Schema::dropIfExists('channels');
    }
};
