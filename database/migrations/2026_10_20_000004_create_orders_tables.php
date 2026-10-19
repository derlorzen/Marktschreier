<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bestellungen aus Billbee, nur was für Umsatz und Gewinn nötig ist.
     * Bewusst keine Namen, Adressen oder E-Mails von Kunden.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('billbee_id')->unique();
            $table->string('order_number')->nullable();
            $table->string('platform')->nullable();
            $table->string('shop_name')->nullable();
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('state')->nullable();
            $table->timestamp('ordered_at')->nullable()->index();
            $table->decimal('total_gross', 12, 2)->default(0);
            $table->decimal('shipping_gross', 12, 2)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->timestamp('billbee_modified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku')->nullable()->index();
            $table->string('ean')->nullable();
            $table->string('title')->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('total_gross', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('cost_net', 12, 4)->nullable(); // EK zum Zeitpunkt des Imports
            $table->index(['variant_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
