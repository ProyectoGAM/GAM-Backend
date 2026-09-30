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
        Schema::create('stock_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_unit_id')->nullable()->constrained('production_units')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('normalized_name', 160)->unique();
            $table->string('status', 20)->default('active');
            $table->boolean('system_managed')->default(false);
            $table->timestamps();

            $table->index(['status', 'name']);
        });

        DB::statement("ALTER TABLE stock_locations ADD CONSTRAINT stock_locations_status_check CHECK (status IN ('active', 'inactive'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_locations');
    }
};
