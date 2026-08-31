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
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('stock_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->decimal('on_hand_quantity', 18, 6)->default(0);
            $table->decimal('minimum_quantity', 18, 6)->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'stock_location_id']);
            $table->index(['stock_location_id', 'product_id']);
        });

        DB::statement('ALTER TABLE stock_balances ADD CONSTRAINT stock_balances_quantities_check CHECK (on_hand_quantity >= 0 AND minimum_quantity >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
    }
};
