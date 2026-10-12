<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Permite saldos negativos en lotes abiertos y retira el catálogo exclusivo de mortalidad. */
    public function up(): void
    {
        DB::statement('ALTER TABLE flocks DROP CONSTRAINT flocks_quantities_check');
        DB::statement('ALTER TABLE flocks DROP CONSTRAINT flocks_status_check');
        DB::statement('ALTER TABLE flocks DROP CONSTRAINT flocks_status_quantity_check');
        DB::statement('ALTER TABLE flocks ADD CONSTRAINT flocks_quantities_check CHECK (initial_quantity > 0 AND version > 0)');
        DB::statement("ALTER TABLE flocks ADD CONSTRAINT flocks_status_check CHECK (status IN ('active', 'quarantined', 'finished') AND (status <> 'finished' OR (current_quantity = 0 AND finalized_at IS NOT NULL)))");
        Schema::table('flocks', function (Blueprint $table): void {
            $table->bigInteger('current_quantity')->change();
        });
        Schema::table('mortality_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('mortality_category_id');
        });
        Schema::drop('mortality_categories');
        DB::table('permissions')->whereIn('name', ['mortality-categories.view', 'mortality-categories.manage'])->delete();
    }

    /** Impide inventar categorías o asignaciones históricas eliminadas al retroceder. */
    public function down(): void
    {
        throw new RuntimeException('La eliminación de categorías de mortalidad no es reversible. Restaura un respaldo o aplica una migración correctiva.');
    }
};
