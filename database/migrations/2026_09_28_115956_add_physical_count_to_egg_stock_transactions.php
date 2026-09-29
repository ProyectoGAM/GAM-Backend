<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE egg_stock_transactions DROP CONSTRAINT egg_stock_transactions_values_check');
        DB::statement('ALTER TABLE egg_stock_transactions ALTER COLUMN quantity TYPE bigint');

        Schema::table('egg_stock_transactions', function (Blueprint $table) {
            $table->bigInteger('balance_before')->nullable();
            $table->bigInteger('counted_quantity')->nullable();
            $table->bigInteger('difference')->nullable();
        });

        DB::statement("ALTER TABLE egg_stock_transactions ADD CONSTRAINT egg_stock_transactions_values_check CHECK (quantity >= 0 AND version > 0 AND status IN ('recorded', 'cancelled') AND ((type = 'physical_count' AND balance_before IS NOT NULL AND counted_quantity IS NOT NULL AND difference IS NOT NULL AND counted_quantity >= 0 AND quantity = ABS(difference) AND difference = counted_quantity - balance_before) OR (type IN ('collection_receipt', 'manual_receipt', 'distribution_preparation', 'loss') AND quantity > 0 AND balance_before IS NULL AND counted_quantity IS NULL AND difference IS NULL)))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('egg_stock_transactions')->where('type', 'physical_count')->exists()) {
            throw new RuntimeException('No se pueden revertir conteos físicos de huevos ya registrados.');
        }

        DB::statement('ALTER TABLE egg_stock_transactions DROP CONSTRAINT egg_stock_transactions_values_check');
        Schema::table('egg_stock_transactions', function (Blueprint $table) {
            $table->dropColumn(['balance_before', 'counted_quantity', 'difference']);
        });
        DB::statement('ALTER TABLE egg_stock_transactions ALTER COLUMN quantity TYPE integer');
        DB::statement("ALTER TABLE egg_stock_transactions ADD CONSTRAINT egg_stock_transactions_values_check CHECK (quantity > 0 AND version > 0 AND status IN ('recorded', 'cancelled') AND type IN ('collection_receipt', 'manual_receipt', 'distribution_preparation', 'loss'))");
    }
};
