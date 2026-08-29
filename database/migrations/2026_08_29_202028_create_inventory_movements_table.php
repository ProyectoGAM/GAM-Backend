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
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->char('request_hash', 64);
            $table->string('type', 30);
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('stock_reservation_id')->nullable()->constrained('stock_reservations')->restrictOnDelete();
            $table->string('reference_type', 120)->nullable();
            $table->string('reference_id', 120)->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestampTz('occurred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('reverses_movement_id')->nullable()->constrained('inventory_movements')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['occurred_at', 'id']);
            $table->index(['type', 'occurred_at']);
            $table->index(['supplier_id', 'occurred_at']);
            $table->index(['reference_type', 'reference_id']);
            $table->unique('reverses_movement_id');
        });

        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_type_check CHECK (type IN ('opening_balance', 'receipt', 'issue', 'loss', 'adjustment', 'transfer', 'reservation', 'release', 'consumption', 'reversal'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
