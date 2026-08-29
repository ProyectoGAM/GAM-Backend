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
        Schema::create('stock_reservation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_reservation_id')->constrained('stock_reservations')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('stock_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->string('unit', 20);
            $table->decimal('reserved_quantity', 18, 6);
            $table->decimal('released_quantity', 18, 6)->default(0);
            $table->decimal('consumed_quantity', 18, 6)->default(0);
            $table->timestamps();

            $table->unique(['stock_reservation_id', 'product_id', 'stock_location_id']);
            $table->index(['product_id', 'stock_location_id']);
        });

        DB::statement('ALTER TABLE stock_reservation_lines ADD CONSTRAINT stock_reservation_lines_quantities_check CHECK (reserved_quantity > 0 AND released_quantity >= 0 AND consumed_quantity >= 0 AND released_quantity + consumed_quantity <= reserved_quantity)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_reservation_lines');
    }
};
