<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aufbau wie bei PlentyONE: Der Artikel ist der Vater, jede Variante hat eigene Einstellungen
     * (Hauptvariante, Modell, ASIN, Gewicht, Maße), eigene Merkmale mit Vererbung vom Vater und eigene Bilder.
     */
    public function up(): void
    {
        Schema::table('variants', function (Blueprint $table) {
            $table->boolean('is_main')->default(false)->after('position');
            // nur in Marktschreier: inaktive Varianten bekommen keine neuen Angebote
            $table->boolean('active')->default(true)->after('is_main');
            $table->string('model')->nullable()->after('barcode');
            $table->string('asin', 10)->nullable()->index()->after('model');
            $table->unsignedInteger('weight_g')->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();
        });

        Schema::create('variant_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['variant_id', 'key']);
        });

        Schema::table('product_images', function (Blueprint $table) {
            // leer = gilt für alle Varianten
            $table->json('variant_ids')->nullable();
        });

        // Bestehende Artikel: die erste Variante wird Hauptvariante
        $firsts = [];
        foreach (DB::table('variants')->orderBy('product_id')->orderBy('position')->orderBy('id')->select('id', 'product_id')->cursor() as $row) {
            $firsts[$row->product_id] ??= $row->id;
        }
        foreach (array_chunk(array_values($firsts), 500) as $ids) {
            DB::table('variants')->whereIn('id', $ids)->update(['is_main' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('product_images', fn (Blueprint $t) => $t->dropColumn('variant_ids'));
        Schema::dropIfExists('variant_attributes');
        Schema::table('variants', function (Blueprint $t) {
            $t->dropIndex(['asin']);
            $t->dropColumn(['is_main', 'active', 'model', 'asin', 'weight_g', 'length_mm', 'width_mm', 'height_mm']);
        });
    }
};
