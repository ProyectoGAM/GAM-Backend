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
        Schema::create('inventory_movement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_movement_id')->constrained('inventory_movements')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('stock_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->string('unit', 20);
            $table->decimal('on_hand_delta', 18, 6)->default(0);
            $table->timestamps();

            $table->unique(['inventory_movement_id', 'product_id', 'stock_location_id']);
            $table->index(['product_id', 'stock_location_id', 'created_at']);
        });

        DB::statement('ALTER TABLE inventory_movement_lines ADD CONSTRAINT inventory_movement_lines_delta_check CHECK (on_hand_delta <> 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_movement_lines');
    }
};
