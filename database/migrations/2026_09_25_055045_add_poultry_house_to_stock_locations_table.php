<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Ejecuta la migración. */
    public function up(): void
    {
        Schema::table('stock_locations', function (Blueprint $table) {
            $table->foreignId('poultry_house_id')
                ->nullable()
                ->unique()
                ->after('production_unit_id')
                ->constrained('poultry_houses')
                ->restrictOnDelete();
        });
    }

    /** Revierte la migración. */
    public function down(): void
    {
        Schema::table('stock_locations', function (Blueprint $table) {
            $table->dropForeign(['poultry_house_id']);
            $table->dropUnique(['poultry_house_id']);
            $table->dropColumn('poultry_house_id');
        });
    }
};
