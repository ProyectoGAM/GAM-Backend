<?php

namespace Tests\Feature\Lots;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockMovement;
use App\Models\Lots\FlockOperation;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FlockActivityMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: conserva como no verificable una redistribución cuando una operación posterior carece de fuentes históricas.
    public function test_legacy_backfill_rejects_incomplete_later_operation(): void
    {
        // Preparación: reconstruye el esquema anterior con una redistribución y una operación posterior sin auditoría.
        $movement = $this->runLegacyBackfill(true);

        // Verificación: la reversión histórica queda bloqueada por la cobertura incompleta.
        $this->assertFalse($movement->fresh()->reversal_verified);
        $this->assertDatabaseCount('flock_activities', 2);
    }

    // Flujo: marca verificable una redistribución heredada cuando todos sus lotes y operaciones tienen evidencia.
    public function test_legacy_backfill_verifies_complete_activity_coverage(): void
    {
        // Preparación: reconstruye el esquema anterior sólo con fuentes completas de la redistribución.
        $movement = $this->runLegacyBackfill(false);

        // Verificación: las dos identidades involucradas tienen journal y la reversión puede evaluarse.
        $this->assertTrue($movement->fresh()->reversal_verified);
        $this->assertDatabaseCount('flock_activities', 2);
    }

    // Flujo: deja una redistribución sin verificar cuando una fotografía carece de un campo que consume la reversión.
    public function test_legacy_backfill_rejects_incomplete_reverse_snapshot(): void
    {
        // Preparación: conserva auditorías completas pero elimina la versión del receptor en la fotografía posterior.
        $movement = $this->runLegacyBackfill(false, true);

        // Verificación: la ausencia de un campo consumido bloquea la reversión sin abortar la migración.
        $this->assertFalse($movement->fresh()->reversal_verified);
        $this->assertDatabaseCount('flock_activities', 2);
    }

    // Flujo: revierte y reaplica las restricciones sin perder ni desverificar una unión total histórica válida.
    public function test_migration_down_and_up_preserve_total_existing_history(): void
    {
        // Preparación: deja una unión total con fotografías y auditorías completas en galpones compatibles.
        $actor = User::factory()->create();
        $destinationHouse = PoultryHouse::factory()->create(['bird_capacity' => 200]);
        $source = Flock::factory()->create(['initial_quantity' => 100, 'current_quantity' => 0, 'status' => 'finished', 'finalized_at' => now()]);
        $destination = Flock::factory()->create([
            'poultry_house_id' => $destinationHouse->id,
            'initial_quantity' => 20,
            'current_quantity' => 120,
        ]);
        $operationId = (string) Str::uuid();
        $before = [
            $source->public_id => ['public_id' => $source->public_id, 'poultry_house_id' => $source->poultry_house_id, 'production_unit_id' => $source->production_unit_id, 'current_quantity' => 100, 'status' => 'active', 'version' => 1, 'finalized_at' => null, 'finalization_reason' => null],
            $destination->public_id => ['public_id' => $destination->public_id, 'poultry_house_id' => $destination->poultry_house_id, 'production_unit_id' => $destination->production_unit_id, 'current_quantity' => 20, 'status' => 'active', 'version' => 1, 'finalized_at' => null, 'finalization_reason' => null],
        ];
        $after = [
            $source->public_id => ['public_id' => $source->public_id, 'poultry_house_id' => $source->poultry_house_id, 'production_unit_id' => $source->production_unit_id, 'current_quantity' => 0, 'status' => 'finished', 'version' => 2, 'finalized_at' => now()->toIso8601String(), 'finalization_reason' => 'Unión total histórica.'],
            $destination->public_id => ['public_id' => $destination->public_id, 'poultry_house_id' => $destination->poultry_house_id, 'production_unit_id' => $destination->production_unit_id, 'current_quantity' => 120, 'status' => 'active', 'version' => 2, 'finalized_at' => null, 'finalization_reason' => null],
        ];
        FlockOperation::factory()->create(['operation_id' => $operationId, 'command' => 'flock.redistribute', 'created_by' => $actor->id]);
        $attributes = [
            'public_id' => (string) Str::ulid(),
            'operation_id' => $operationId,
            'type' => 'total_existing',
            'source_flock_id' => $source->id,
            'destination_flock_id' => $destination->id,
            'source_poultry_house_id' => $source->poultry_house_id,
            'destination_poultry_house_id' => $destination->poultry_house_id,
            'quantity' => 100,
            'before' => json_encode($before, JSON_THROW_ON_ERROR),
            'after' => json_encode($after, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_by' => $actor->id,
            'created_at' => now(),
        ];
        DB::table('flock_movements')->insert($attributes);
        $this->insertLegacyAudit($operationId, $source, $actor, $before[$source->public_id], $after[$source->public_id]);
        $this->insertLegacyAudit($operationId, $destination, $actor, $before[$destination->public_id], $after[$destination->public_id]);

        // Mutación: ejecuta el rollback de la migración dentro de la transacción de la prueba.
        $migration = require base_path('database/migrations/2026_09_21_194750_add_flock_occupancy_and_activity_constraints.php');
        if (! $migration instanceof Migration) {
            throw new \LogicException('La migración de Lotes no devolvió una instancia válida.');
        }
        $migration->down();

        // Verificación: conserva la fila histórica y el CHECK compatible con total_existing.
        $this->assertDatabaseHas('flock_movements', ['public_id' => $attributes['public_id'], 'type' => 'total_existing']);
        $this->assertFalse(Schema::hasColumn('flock_movements', 'reversal_verified'));
        $this->assertFalse(Schema::hasTable('flock_activities'));

        // Mutación: reaplica la migración y reconstruye el journal de la unión verificable.
        $migration->up();

        // Verificación: la unión histórica vuelve a quedar verificable y sus dos lotes tienen actividad.
        $this->assertTrue(FlockMovement::query()->where('public_id', $attributes['public_id'])->firstOrFail()->reversal_verified);
        $this->assertDatabaseCount('flock_activities', 2);
    }

    private function runLegacyBackfill(bool $withIncompleteOperation, bool $withIncompleteSnapshot = false): FlockMovement
    {
        // Preparación: retira sólo las estructuras agregadas por la migración para representar datos heredados.
        $this->dropCurrentConstraints();
        $actor = User::factory()->create();
        $source = Flock::factory()->create(['initial_quantity' => 100, 'current_quantity' => 80, 'version' => 2]);
        $destination = Flock::factory()->create(['initial_quantity' => 20, 'current_quantity' => 20]);
        $operationId = (string) Str::uuid();
        $before = [
            $source->public_id => [
                'public_id' => $source->public_id,
                'poultry_house_id' => $source->poultry_house_id,
                'production_unit_id' => $source->production_unit_id,
                'current_quantity' => 100,
                'status' => 'active',
                'version' => 1,
                'finalized_at' => null,
                'finalization_reason' => null,
            ],
        ];
        $after = [
            $source->public_id => [
                'public_id' => $source->public_id,
                'poultry_house_id' => $source->poultry_house_id,
                'production_unit_id' => $source->production_unit_id,
                'current_quantity' => 80,
                'status' => 'active',
                'version' => 2,
                'finalized_at' => null,
                'finalization_reason' => null,
            ],
            $destination->public_id => [
                'public_id' => $destination->public_id,
                'poultry_house_id' => $destination->poultry_house_id,
                'production_unit_id' => $destination->production_unit_id,
                'current_quantity' => 20,
                'status' => 'active',
                'version' => 1,
                'finalized_at' => null,
                'finalization_reason' => null,
            ],
        ];
        if ($withIncompleteSnapshot) {
            // Mutación: simula una fotografía heredada que no permite restaurar la versión del receptor.
            unset($after[$destination->public_id]['version']);
        }

        // Mutación: conserva la operación, sus fotografías y auditorías mínimas del histórico.
        FlockOperation::factory()->create(['operation_id' => $operationId, 'command' => 'flock.redistribute', 'created_by' => $actor->id]);
        $movementId = DB::table('flock_movements')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'operation_id' => $operationId,
            'type' => 'partial_new',
            'source_flock_id' => $source->id,
            'destination_flock_id' => $destination->id,
            'source_poultry_house_id' => $source->poultry_house_id,
            'destination_poultry_house_id' => $destination->poultry_house_id,
            'quantity' => 20,
            'before' => json_encode($before, JSON_THROW_ON_ERROR),
            'after' => json_encode($after, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
        $this->insertLegacyAudit($operationId, $source, $actor, $before[$source->public_id], $after[$source->public_id]);
        $this->insertLegacyAudit($operationId, $destination, $actor, [], $after[$destination->public_id]);

        if ($withIncompleteOperation) {
            // Mutación: agrega una operación que podría tocar lotes pero no dejó movimiento ni auditoría.
            FlockOperation::factory()->create(['command' => 'flock.update', 'created_by' => $actor->id]);
        }

        // Mutación: ejecuta la migración real sobre el estado heredado dentro de la transacción de la prueba.
        $migration = require base_path('database/migrations/2026_09_21_194750_add_flock_occupancy_and_activity_constraints.php');
        if (! $migration instanceof Migration) {
            throw new \LogicException('La migración de Lotes no devolvió una instancia válida.');
        }
        $migration->up();

        return FlockMovement::query()->findOrFail($movementId);
    }

    private function dropCurrentConstraints(): void
    {
        // Consulta: elimina sólo las restricciones nuevas para reproducir el contrato histórico sin tocar la base local.
        Schema::dropIfExists('flock_activities');
        DB::statement('ALTER TABLE flock_movements DROP CONSTRAINT IF EXISTS flock_movements_type_check');
        DB::statement('ALTER TABLE flocks DROP CONSTRAINT IF EXISTS flocks_status_quantity_check');
        DB::statement('DROP INDEX IF EXISTS flocks_open_house_unique');
        Schema::table('flock_movements', function (Blueprint $table): void {
            $table->dropColumn('reversal_verified');
        });
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    private function insertLegacyAudit(string $operationId, Flock $flock, User $actor, array $before, array $after): void
    {
        // Mutación: registra una auditoría histórica vinculada al lote y a la operación.
        DB::table('activity_log')->insert([
            'log_name' => 'lots',
            'description' => 'Redistribución histórica',
            'subject_type' => Flock::class,
            'subject_id' => $flock->id,
            'event' => 'flock_redistributed',
            'causer_type' => User::class,
            'causer_id' => $actor->id,
            'operation_id' => $operationId,
            'trace_id' => (string) Str::uuid(),
            'source' => 'test',
            'up_id' => $flock->production_unit_id,
            'attribute_changes' => json_encode(['old' => $before, 'new' => $after], JSON_THROW_ON_ERROR),
            'properties' => json_encode(['subject_snapshot' => $after], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
