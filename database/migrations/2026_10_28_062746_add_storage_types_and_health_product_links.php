<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_locations', function (Blueprint $table): void {
            $table->string('type', 24)->default('unknown');
        });
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('system_key', 50)->nullable()->unique();
        });
        Schema::table('medicines', function (Blueprint $table): void {
            $table->foreignId('product_id')->nullable()->unique()->constrained('products')->restrictOnDelete();
        });
        Schema::table('medicine_applications', function (Blueprint $table): void {
            $table->foreignId('inventory_movement_id')->nullable()->unique()->constrained('inventory_movements')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE stock_locations ADD CONSTRAINT stock_locations_type_check CHECK (type IN ('warehouse', 'feed_plant', 'egg_account', 'health', 'unknown'))");
        DB::statement("CREATE UNIQUE INDEX stock_locations_health_unit_unique ON stock_locations (production_unit_id) WHERE type = 'health'");
        DB::statement("ALTER TABLE stock_locations ADD CONSTRAINT stock_locations_health_structure_check CHECK (type <> 'health' OR (production_unit_id IS NOT NULL AND poultry_house_id IS NULL))");
    }

    public function down(): void
    {
        Schema::table('medicine_applications', fn (Blueprint $table) => $table->dropConstrainedForeignId('inventory_movement_id'));
        Schema::table('medicines', fn (Blueprint $table) => $table->dropConstrainedForeignId('product_id'));
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn('system_key'));
        DB::statement('DROP INDEX stock_locations_health_unit_unique');
        DB::statement('ALTER TABLE stock_locations DROP CONSTRAINT stock_locations_type_check, DROP CONSTRAINT stock_locations_health_structure_check');
        Schema::table('stock_locations', fn (Blueprint $table) => $table->dropColumn('type'));
    }
};
