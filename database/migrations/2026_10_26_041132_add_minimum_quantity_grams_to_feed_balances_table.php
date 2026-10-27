<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feed_balances', function (Blueprint $table): void {
            $table->bigInteger('minimum_quantity_grams')->nullable();
        });
        DB::statement('ALTER TABLE feed_balances ADD CONSTRAINT feed_balance_minimum_range CHECK (minimum_quantity_grams IS NULL OR minimum_quantity_grams BETWEEN 0 AND 999999999999)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE feed_balances DROP CONSTRAINT feed_balance_minimum_range');
        Schema::table('feed_balances', function (Blueprint $table): void {
            $table->dropColumn('minimum_quantity_grams');
        });
    }
};
