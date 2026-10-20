<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** PlentyONE andocken: Artikel, Varianten und Aufträge mit ihrer plenty-ID. */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('plenty_id')->nullable()->unique()->after('shopify_id');
        });
        Schema::table('variants', function (Blueprint $table) {
            $table->unsignedBigInteger('plenty_variation_id')->nullable()->unique()->after('shopify_id');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('billbee_id')->nullable()->change();
            $table->unsignedBigInteger('plenty_id')->nullable()->unique()->after('billbee_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('plenty_id'));
        Schema::table('variants', fn (Blueprint $t) => $t->dropColumn('plenty_variation_id'));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('plenty_id'));
    }
};
