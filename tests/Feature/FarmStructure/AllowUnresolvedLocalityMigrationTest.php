<?php

namespace Tests\Feature\FarmStructure;

use App\Models\FarmStructure\ProductionUnit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AllowUnresolvedLocalityMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_down_rejects_unresolved_rural_units_without_changing_the_schema_or_rows(): void
    {
        // Preparación: conserva una unidad rural sin localidad del catálogo.
        $productionUnit = ProductionUnit::factory()->create(['locality_id' => null]);
        $migration = $this->migration();

        // Acción: el rollback debe detenerse antes de intentar restaurar NOT NULL.
        try {
            $migration->down();
            $this->fail('El rollback debe detenerse si existen unidades sin localidad.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'No se puede revertir la nulabilidad de localidad mientras existan unidades productivas sin localidad catalogada.',
                $exception->getMessage(),
            );
        }

        // Verificación: mantiene la fila y la columna nullable tras el rechazo.
        $this->assertDatabaseHas('production_units', [
            'id' => $productionUnit->getKey(),
            'locality_id' => null,
        ]);
        $this->assertTrue($this->localityIsNullable());
    }

    public function test_down_and_up_restore_nullability_when_no_unresolved_units_exist(): void
    {
        // Preparación: no hay unidades rurales que impidan el rollback.
        ProductionUnit::factory()->create();
        $migration = $this->migration();

        // Acción: revierte la migración y después la reaplica para restaurar el esquema de prueba.
        $migration->down();
        $this->assertFalse($this->localityIsNullable());
        $migration->up();

        // Verificación: la columna vuelve a aceptar unidades aún sin localidad confirmada.
        $this->assertTrue($this->localityIsNullable());
    }

    private function migration(): Migration
    {
        $migration = require base_path('database/migrations/2026_10_04_084038_allow_unresolved_locality_for_production_units.php');

        if (! $migration instanceof Migration) {
            throw new \LogicException('La migración de localidad no devolvió una instancia válida.');
        }

        return $migration;
    }

    private function localityIsNullable(): bool
    {
        $column = DB::selectOne("SELECT is_nullable FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'production_units' AND column_name = 'locality_id'");

        return $column?->is_nullable === 'YES';
    }
}
