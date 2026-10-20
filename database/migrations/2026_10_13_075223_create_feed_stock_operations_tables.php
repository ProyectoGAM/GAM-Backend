<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feed_recipe_id')->constrained()->restrictOnDelete();
            $table->foreignId('production_unit_id')->constrained()->restrictOnDelete();
            $table->bigInteger('quantity_grams')->default(0);
            $table->timestamps();
            $table->unique(['feed_recipe_id', 'production_unit_id']);
            $table->index(['production_unit_id', 'id']);
        });
        Schema::create('feed_stock_operations', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 32);
            $table->uuid('operation_id')->unique();
            $table->uuid('idempotency_key');
            $table->char('request_hash', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('production_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('feed_recipe_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('poultry_house_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('stock_location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('inventory_movement_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('cancels_operation_id')->nullable()->unique()->constrained('feed_stock_operations')->restrictOnDelete();
            $table->bigInteger('quantity_grams');
            $table->bigInteger('factor')->nullable();
            $table->string('adjustment_mode', 20)->nullable();
            $table->text('reason')->nullable();
            $table->decimal('balance_before', 18, 6);
            $table->decimal('balance_after', 18, 6);
            $table->decimal('delta_grams', 19, 6);
            $table->jsonb('snapshot');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at');
            $table->unique(['created_by', 'idempotency_key']);
            $table->index(['kind', 'id']);
            $table->index(['production_unit_id', 'kind', 'id'], 'feed_operations_up_history');
            $table->index(['feed_recipe_id', 'kind', 'id'], 'feed_operations_recipe_history');
        });
        DB::statement('ALTER TABLE feed_balances ADD CONSTRAINT feed_balance_range CHECK (quantity_grams BETWEEN -999999999999 AND 999999999999)');
        DB::statement("ALTER TABLE feed_stock_operations ADD CONSTRAINT feed_operation_kind CHECK (kind IN ('manufacture', 'cancellation', 'ingredient_adjustment', 'feed_adjustment'))");
        DB::statement('ALTER TABLE feed_stock_operations ADD CONSTRAINT feed_operation_quantity_range CHECK (quantity_grams BETWEEN -999999999999 AND 999999999999)');
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_stock_operations');
        Schema::dropIfExists('feed_balances');
    }
};
