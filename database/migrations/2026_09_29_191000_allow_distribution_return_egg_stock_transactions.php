<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE egg_stock_transactions DROP CONSTRAINT egg_stock_transactions_values_check');
        DB::statement("ALTER TABLE egg_stock_transactions ADD CONSTRAINT egg_stock_transactions_values_check CHECK (quantity > 0 AND version > 0 AND status IN ('recorded', 'cancelled') AND type IN ('collection_receipt', 'manual_receipt', 'distribution_preparation', 'distribution_return', 'loss'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE egg_stock_transactions DROP CONSTRAINT egg_stock_transactions_values_check');
        DB::statement("ALTER TABLE egg_stock_transactions ADD CONSTRAINT egg_stock_transactions_values_check CHECK (quantity > 0 AND version > 0 AND status IN ('recorded', 'cancelled') AND type IN ('collection_receipt', 'manual_receipt', 'distribution_preparation', 'loss'))");
    }
};
