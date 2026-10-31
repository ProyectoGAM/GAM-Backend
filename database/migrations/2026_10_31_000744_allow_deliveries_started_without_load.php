<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->boolean('started_without_load')->default(false);
            $table->foreignId('production_unit_id')->nullable()->change();
        });

        Schema::table('delivery_loads', function (Blueprint $table): void {
            $table->timestampTz('occurred_at')->nullable();
        });

        DB::table('delivery_loads')->whereNull('occurred_at')->update(['occurred_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        if (DB::table('deliveries')->whereNull('production_unit_id')->exists()) {
            throw new RuntimeException('No se puede revertir mientras existan repartos sin unidad productiva.');
        }

        Schema::table('delivery_loads', function (Blueprint $table): void {
            $table->dropColumn('occurred_at');
        });

        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropColumn('started_without_load');
            $table->foreignId('production_unit_id')->nullable(false)->change();
        });
    }
};
