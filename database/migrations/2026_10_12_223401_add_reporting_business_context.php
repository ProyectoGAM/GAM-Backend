<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Conserva contexto nuevo sin inventar datos para ejecuciones históricas. */
    public function up(): void
    {
        foreach (['vaccination_applications', 'ration_changes', 'manual_practices', 'medicine_applications'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('poultry_house_id')->nullable()->constrained('poultry_houses')->restrictOnDelete();
                $table->foreignId('production_unit_id')->nullable()->constrained('production_units')->restrictOnDelete();
            });
        }
        Schema::table('vaccination_applications', function (Blueprint $table): void {
            $table->string('vaccine_batch', 160)->nullable();
        });
        Schema::create('feed_supplies', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('flock_id')->constrained('flocks')->restrictOnDelete();
            $table->foreignId('poultry_house_id')->constrained('poultry_houses')->restrictOnDelete();
            $table->foreignId('production_unit_id')->constrained('production_units')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('product_name_snapshot', 160);
            $table->decimal('quantity', 18, 6);
            $table->string('unit', 10);
            $table->timestampTz('occurred_at');
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('recorded');
            $table->uuid('operation_id')->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['poultry_house_id', 'occurred_at', 'id']);
            $table->index(['flock_id', 'occurred_at', 'id']);
        });
        DB::statement("ALTER TABLE feed_supplies ADD CONSTRAINT feed_supplies_values_check CHECK (quantity > 0 AND unit IN ('g', 'kg') AND status IN ('recorded', 'cancelled'))");
        DB::statement('ALTER TABLE egg_stock_transactions DROP CONSTRAINT egg_stock_transactions_values_check');
        DB::statement("ALTER TABLE egg_stock_transactions ADD CONSTRAINT egg_stock_transactions_values_check CHECK (quantity > 0 AND version > 0 AND status IN ('recorded', 'cancelled') AND type IN ('collection_receipt', 'manual_receipt', 'distribution_preparation', 'distribution_return', 'loss', 'donation'))");
    }

    public function down(): void
    {
        if (DB::table('egg_stock_transactions')->where('type', 'donation')->exists()) {
            throw new RuntimeException('No se puede revertir la migración mientras existan donaciones. Conserva su historial.');
        }
        DB::statement('ALTER TABLE egg_stock_transactions DROP CONSTRAINT egg_stock_transactions_values_check');
        DB::statement("ALTER TABLE egg_stock_transactions ADD CONSTRAINT egg_stock_transactions_values_check CHECK (quantity > 0 AND version > 0 AND status IN ('recorded', 'cancelled') AND type IN ('collection_receipt', 'manual_receipt', 'distribution_preparation', 'distribution_return', 'loss'))");
        Schema::dropIfExists('feed_supplies');
        Schema::table('vaccination_applications', fn (Blueprint $table) => $table->dropColumn('vaccine_batch'));
        foreach (['vaccination_applications', 'ration_changes', 'manual_practices', 'medicine_applications'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('poultry_house_id');
                $table->dropConstrainedForeignId('production_unit_id');
            });
        }
    }
};
