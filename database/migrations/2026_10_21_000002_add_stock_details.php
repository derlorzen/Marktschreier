<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('variants', function (Blueprint $table) {
            $table->json('stock_locations')->nullable()->after('stock');   // Billbee: Bestand je Lager
        });
        Schema::table('listings', function (Blueprint $table) {
            $table->integer('remote_quantity')->nullable()->after('remote_price'); // auf dem Marktplatz angezeigte Menge
            $table->string('fulfillment')->nullable()->after('remote_quantity');  // FBM | FBA
            $table->json('fba')->nullable()->after('fulfillment');                // fulfillable, inbound, reserved
        });
    }

    public function down(): void
    {
        Schema::table('variants', fn (Blueprint $t) => $t->dropColumn('stock_locations'));
        Schema::table('listings', fn (Blueprint $t) => $t->dropColumn(['remote_quantity', 'fulfillment', 'fba']));
    }
};
