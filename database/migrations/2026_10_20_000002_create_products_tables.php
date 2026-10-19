<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('shopify_id')->nullable()->unique();
            $table->string('handle')->nullable();
            $table->string('title');
            $table->longText('description_html')->nullable();
            $table->string('vendor')->nullable()->index();
            $table->string('product_type')->nullable()->index();
            $table->json('tags')->nullable();
            $table->string('status', 16)->default('ACTIVE')->index();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->json('options')->nullable();
            $table->timestamp('shopify_updated_at')->nullable();
            // Werte, wie sie zuletzt in Shopify standen. Basis für "was hat sich lokal geändert".
            $table->json('synced_snapshot')->nullable();
            // synced | pending | error | conflict
            $table->string('push_status', 16)->default('synced')->index();
            $table->text('push_error')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamps();
            $table->index('title');
        });

        Schema::create('variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('shopify_id')->nullable()->unique();
            $table->string('inventory_item_id')->nullable();
            $table->string('title')->nullable();
            $table->string('sku')->nullable()->index();
            $table->string('barcode')->nullable()->index();
            $table->string('option1')->nullable();
            $table->string('option2')->nullable();
            $table->string('option3')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('compare_at_price', 12, 2)->nullable();
            $table->unsignedInteger('position')->default(1);
            $table->json('synced_snapshot')->nullable();
            // Aus Billbee, nur lesend
            $table->unsignedBigInteger('billbee_id')->nullable()->index();
            $table->decimal('cost_net', 12, 4)->nullable();
            $table->integer('stock')->nullable();
            $table->timestamp('billbee_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('shopify_id')->nullable();
            $table->text('url');
            $table->string('alt')->nullable();
            $table->unsignedInteger('position')->default(1);
            $table->timestamps();
        });

        Schema::create('product_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'key']);
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            // shopify_import | shopify_push | billbee_sync
            $table->string('type', 32)->index();
            // running | ok | failed
            $table->string('status', 16)->default('running');
            $table->json('stats')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('change_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('field');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            // user | import | billbee | push
            $table->string('source', 16);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_logs');
        Schema::dropIfExists('sync_runs');
        Schema::dropIfExists('product_attributes');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('variants');
        Schema::dropIfExists('products');
    }
};
