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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 80)->unique();
            $table->string('name', 160);
            $table->string('normalized_name', 160)->unique();
            $table->string('kind', 30);
            $table->string('base_unit', 20);
            $table->boolean('stock_tracked')->default(true);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['kind', 'status', 'name']);
        });

        DB::statement("ALTER TABLE products ADD CONSTRAINT products_kind_check CHECK (kind IN ('raw_material', 'supply', 'finished_feed', 'egg', 'medicine', 'vaccine', 'other'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_base_unit_check CHECK (base_unit IN ('unit', 'kg', 'g', 'l', 'ml', 'dose'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('active', 'inactive'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
