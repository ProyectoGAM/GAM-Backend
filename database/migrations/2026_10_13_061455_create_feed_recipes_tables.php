<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_recipes', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->text('notes')->nullable();
            $table->bigInteger('yield_grams');
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('operation_id')->unique();
            $table->uuid('idempotency_key');
            $table->char('request_hash', 64);
            $table->timestamps();
            $table->unique(['created_by', 'idempotency_key']);
            $table->index(['status', 'id']);
        });
        Schema::create('feed_recipe_ingredients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feed_recipe_id')->constrained('feed_recipes')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->bigInteger('quantity_grams');
            $table->timestamps();
            $table->unique(['feed_recipe_id', 'product_id']);
            $table->index('product_id');
        });
        DB::statement('ALTER TABLE feed_recipes ADD CONSTRAINT feed_recipes_yield_positive CHECK (yield_grams > 0)');
        DB::statement("ALTER TABLE feed_recipes ADD CONSTRAINT feed_recipes_status_check CHECK (status IN ('active', 'inactive'))");
        DB::statement('ALTER TABLE feed_recipe_ingredients ADD CONSTRAINT feed_recipe_ingredients_quantity_positive CHECK (quantity_grams > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_recipe_ingredients');
        Schema::dropIfExists('feed_recipes');
    }
};
