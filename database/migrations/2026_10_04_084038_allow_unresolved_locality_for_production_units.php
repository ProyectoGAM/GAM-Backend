<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_units', function (Blueprint $table) {
            $table->unsignedBigInteger('locality_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('production_units')->whereNull('locality_id')->exists()) {
            throw new RuntimeException('No se puede revertir la nulabilidad de localidad mientras existan unidades productivas sin localidad catalogada.');
        }

        Schema::table('production_units', function (Blueprint $table) {
            $table->unsignedBigInteger('locality_id')->nullable(false)->change();
        });
    }
};
