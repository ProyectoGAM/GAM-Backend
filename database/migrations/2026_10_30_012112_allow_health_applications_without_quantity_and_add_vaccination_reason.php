<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Conserva las cantidades históricas y permite registros sanitarios sin stock. */
    public function up(): void
    {
        Schema::table('medicine_applications', function (Blueprint $table): void {
            $table->unsignedInteger('quantity')->nullable()->change();
            $table->string('reason', 500)->nullable()->change();
            $table->unsignedBigInteger('stock_movement_id')->nullable()->change();
        });
        Schema::table('vaccination_applications', function (Blueprint $table): void {
            $table->string('reason', 500)->nullable();
        });
    }

    /** Evita un rollback que invente cantidades o elimine motivos del historial. */
    public function down(): void
    {
        throw new RuntimeException('Esta migración conserva registros sanitarios sin cantidad; no admite una reversión destructiva.');
    }
};
