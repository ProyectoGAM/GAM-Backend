<?php

namespace Tests\Feature\Lots;

use App\Enums\Lots\FlockStatus;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockMovement;
use App\Models\Lots\MortalityCategory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MortalityEndpointTest extends LotsTestCase
{
    // Flujo: registra, corrige y cancela mortalidad sin reescribir el historial.
    public function test_corrections_restore_live_quantity_and_append_audit_history(): void
    {
        // Preparación: configura un lote y una categoría activa.
        $this->signIn();
        $flock = $this->flock(100);
        $category = MortalityCategory::factory()->create();

        // Request: registra cinco bajas y conserva la cantidad inicial.
        $created = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 5, 'mortality_category_id' => $category->id,
        ])->assertCreated()->assertJsonPath('data.flock.current_quantity', 95)->assertJsonPath('data.flock.initial_quantity', 100);
        $id = $created->json('data.mortality.id');
        $originalMovementId = $created->json('data.movement.id');
        $original = FlockMovement::query()->where('public_id', $originalMovementId)->firstOrFail()->getAttributes();

        // Request: corrige a tres bajas mediante un movimiento compensatorio de dos aves.
        $this->command('PATCH', "/mortalities/{$id}", [
            'version' => 1, 'flock_version' => 2, 'quantity' => 3, 'reason' => 'Recuento verificado',
        ])->assertOk()->assertJsonPath('data.flock.current_quantity', 97)->assertJsonPath('data.mortality.quantity', 3)
            ->assertJsonPath('data.movement.quantity', 2);

        // Request: cancela la anotación y restaura el total sin borrar el registro.
        $this->command('POST', "/mortalities/{$id}/cancellation", [
            'version' => 2, 'flock_version' => 3, 'reason' => 'Registro duplicado en la libreta',
        ])->assertOk()->assertJsonPath('data.flock.current_quantity', 100)->assertJsonPath('data.mortality.status', 'cancelled');
        $this->assertDatabaseCount('mortality_records', 1);
        $this->assertDatabaseCount('flock_movements', 3);
        $this->assertEquals($original, FlockMovement::query()->where('public_id', $originalMovementId)->firstOrFail()->getAttributes());
        $this->assertDatabaseHas('activity_log', ['event' => 'mortality_recorded']);
        $this->assertDatabaseHas('activity_log', ['event' => 'mortality_corrected']);
        $this->assertDatabaseHas('activity_log', ['event' => 'mortality_cancelled']);
    }

    // Flujo: las aves en cuarentena pueden registrar mortalidad y el lote se finaliza al agotarse.
    public function test_quarantine_and_loss_of_all_birds_finish_the_flock(): void
    {
        // Preparación: crea un lote pequeño en cuarentena.
        $this->signIn();
        $flock = Flock::factory()->quarantined()->create(['initial_quantity' => 3, 'current_quantity' => 3]);
        $category = MortalityCategory::factory()->create();

        // Request: registra todas las bajas y aplica la finalización automática.
        $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 3, 'mortality_category_id' => $category->id,
        ])->assertCreated()->assertJsonPath('data.flock.current_quantity', 0)->assertJsonPath('data.flock.status', 'finished');
        $this->assertSame(FlockStatus::Finished, $flock->fresh()->status);
    }

    // Flujo: exige confirmación y reactiva atómicamente un lote agotado por mortalidad.
    public function test_correction_reactivation_requires_confirmation_and_restores_status(): void
    {
        // Preparación: agota un lote activo con una mortalidad registrada.
        $this->signIn();
        $flock = $this->flock(3);
        $category = MortalityCategory::factory()->create();
        $created = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 3, 'mortality_category_id' => $category->id,
        ])->assertCreated();
        $url = '/mortalities/'.$created->json('data.mortality.id');

        // Request: rechaza la restitución sin modificar el lote finalizado.
        $this->command('PATCH', $url, [
            'version' => 1, 'flock_version' => 2, 'quantity' => 1, 'reason' => 'Falta confirmación',
        ])->assertConflict();
        $this->assertSame(FlockStatus::Finished, $flock->fresh()->status);
        $this->assertSame(0, $flock->fresh()->current_quantity);

        // Request: confirma la corrección y restaura el estado anterior en la misma operación.
        $this->command('PATCH', $url, [
            'version' => 1, 'flock_version' => 2, 'quantity' => 1,
            'confirm_reactivation' => true, 'reason' => 'Recuento confirmado',
        ])->assertOk()->assertJsonPath('data.flock.status', 'active')->assertJsonPath('data.flock.current_quantity', 2);
        $this->assertNull($flock->fresh()->finalized_at);
    }

    // Flujo: una corrección neutra posterior conserva la terminalidad y no impide una restitución confirmada.
    public function test_neutral_correction_after_exhaustion_still_allows_confirmed_reactivation(): void
    {
        // Preparación: agota un lote y conserva la mortalidad registrada para corregirla.
        $this->signIn();
        $flock = $this->flock(3);
        $category = MortalityCategory::factory()->create();
        $created = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 3, 'mortality_category_id' => $category->id,
        ])->assertCreated();
        $url = '/mortalities/'.$created->json('data.mortality.id');

        // Request: corrige notas sin devolver aves y mantiene el lote finalizado.
        $this->command('PATCH', $url, [
            'version' => 1, 'flock_version' => 2, 'quantity' => 3, 'notes' => 'Corrección neutra', 'reason' => 'Ajuste documental',
        ])->assertOk()->assertJsonPath('data.flock.status', 'finished')->assertJsonPath('data.flock.current_quantity', 0);

        // Request: devuelve aves con confirmación usando la última transición mortal verificable.
        $this->command('PATCH', $url, [
            'version' => 2, 'flock_version' => 3, 'quantity' => 1, 'confirm_reactivation' => true, 'reason' => 'Recuento confirmado',
        ])->assertOk()->assertJsonPath('data.flock.status', 'active')->assertJsonPath('data.flock.current_quantity', 2);
    }

    // Flujo: una redistribución histórica huérfana posterior impide reactivar el lote por incertidumbre.
    public function test_orphaned_history_after_mortality_blocks_reactivation(): void
    {
        // Preparación: agota el lote y deja un receptor independiente para representar la unión histórica.
        $this->signIn();
        $flock = $this->flock(3);
        $category = MortalityCategory::factory()->create();
        $created = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 3, 'mortality_category_id' => $category->id,
        ])->assertCreated();
        $destination = $this->flock(1);

        // Mutación: agrega un movimiento posterior sin FlockOperation ni marca de actividad.
        DB::table('flock_movements')->insert([
            'public_id' => (string) Str::ulid(),
            'operation_id' => (string) Str::uuid(),
            'type' => 'total_existing',
            'source_flock_id' => $flock->id,
            'destination_flock_id' => $destination->id,
            'source_poultry_house_id' => $flock->poultry_house_id,
            'destination_poultry_house_id' => $destination->poultry_house_id,
            'quantity' => 0,
            'before' => json_encode([], JSON_THROW_ON_ERROR),
            'after' => json_encode([], JSON_THROW_ON_ERROR),
            'occurred_at' => now()->addSecond(),
            'created_by' => auth()->id(),
            'created_at' => now()->addSecond(),
        ]);

        // Request: rechaza la restitución confirmada porque el historial posterior no puede ordenarse.
        $this->command('PATCH', '/mortalities/'.$created->json('data.mortality.id'), [
            'version' => 1, 'flock_version' => 2, 'quantity' => 1, 'confirm_reactivation' => true, 'reason' => 'Historial huérfano',
        ])->assertConflict();
        $this->assertSame(FlockStatus::Finished, $flock->fresh()->status);
        $this->assertSame(0, $flock->fresh()->current_quantity);
    }

    // Flujo: una auditoría huérfana anterior con el mismo instante no impide una restitución verificable.
    public function test_orphaned_audit_before_terminality_at_same_timestamp_does_not_block_reactivation(): void
    {
        // Preparación: congela el instante, registra una baja y agrega una auditoría anterior sin marca.
        $this->signIn();
        $this->travelTo(CarbonImmutable::parse('2026-09-21 12:00:00'));
        $flock = $this->flock(3);
        $category = MortalityCategory::factory()->create();
        $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 1, 'mortality_category_id' => $category->id,
        ])->assertCreated();
        $timestamp = now();
        DB::table('activity_log')->insert([
            'log_name' => 'lots',
            'description' => 'Auditoría histórica sin marca',
            'subject_type' => Flock::class,
            'subject_id' => $flock->id,
            'event' => 'legacy_orphan',
            'causer_type' => null,
            'causer_id' => null,
            'operation_id' => (string) Str::uuid(),
            'trace_id' => null,
            'source' => 'test',
            'up_id' => null,
            'attribute_changes' => json_encode([], JSON_THROW_ON_ERROR),
            'properties' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $terminal = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 2, 'quantity' => 2, 'mortality_category_id' => $category->id,
        ])->assertCreated();

        // Request: permite la restitución porque sólo considera auditorías posteriores por su identificador.
        $this->command('PATCH', '/mortalities/'.$terminal->json('data.mortality.id'), [
            'version' => 1, 'flock_version' => 3, 'quantity' => 1,
            'confirm_reactivation' => true, 'reason' => 'Recuento confirmado',
        ])->assertOk()->assertJsonPath('data.flock.status', 'active')->assertJsonPath('data.flock.current_quantity', 1);
    }

    // Flujo: una marca antigua con el mismo identificador no prueba actividad posterior a la terminalidad.
    public function test_reused_operation_mark_before_terminality_does_not_allow_reactivation(): void
    {
        // Preparación: registra una mortalidad previa y después agota el lote con otra operación.
        $this->signIn();
        $flock = $this->flock(3);
        $category = MortalityCategory::factory()->create();
        $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 1, 'mortality_category_id' => $category->id,
        ])->assertCreated();
        $previousOperationId = (string) FlockMovement::query()
            ->where('source_flock_id', $flock->id)
            ->where('type', 'mortality')
            ->orderBy('id')
            ->value('operation_id');
        $this->assertNotSame('', $previousOperationId);
        $terminal = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 2, 'quantity' => 2, 'mortality_category_id' => $category->id,
        ])->assertCreated();

        // Mutación: agrega un movimiento posterior sin una marca nueva para el identificador antiguo.
        DB::table('flock_movements')->insert([
            'public_id' => (string) Str::ulid(),
            'operation_id' => $previousOperationId,
            'type' => 'departure',
            'source_flock_id' => $flock->id,
            'destination_flock_id' => null,
            'source_poultry_house_id' => $flock->poultry_house_id,
            'destination_poultry_house_id' => null,
            'quantity' => 0,
            'before' => json_encode([], JSON_THROW_ON_ERROR),
            'after' => json_encode([], JSON_THROW_ON_ERROR),
            'occurred_at' => now()->addSecond(),
            'created_by' => auth()->id(),
            'created_at' => now()->addSecond(),
        ]);

        // Request: rechaza la restitución porque la única marca coincide con una operación anterior.
        $this->command('PATCH', '/mortalities/'.$terminal->json('data.mortality.id'), [
            'version' => 1, 'flock_version' => 3, 'quantity' => 1,
            'confirm_reactivation' => true, 'reason' => 'Operación reutilizada',
        ])->assertConflict();
        $this->assertSame(FlockStatus::Finished, $flock->fresh()->status);
        $this->assertSame(0, $flock->fresh()->current_quantity);
    }

    // Flujo: un movimiento posterior que reutiliza la operación terminal impide reactivar por incertidumbre.
    public function test_reused_terminal_operation_after_terminality_blocks_reactivation(): void
    {
        // Preparación: agota el lote y conserva el identificador de la operación terminal.
        $this->signIn();
        $flock = $this->flock(3);
        $category = MortalityCategory::factory()->create();
        $terminal = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 3, 'mortality_category_id' => $category->id,
        ])->assertCreated();
        $terminalOperationId = (string) FlockMovement::query()
            ->where('source_flock_id', $flock->id)
            ->where('type', 'mortality')
            ->orderByDesc('id')
            ->value('operation_id');
        $this->assertNotSame('', $terminalOperationId);

        // Mutación: agrega un movimiento posterior con la misma operación y sin marca nueva.
        DB::table('flock_movements')->insert([
            'public_id' => (string) Str::ulid(),
            'operation_id' => $terminalOperationId,
            'type' => 'departure',
            'source_flock_id' => $flock->id,
            'destination_flock_id' => null,
            'source_poultry_house_id' => $flock->poultry_house_id,
            'destination_poultry_house_id' => null,
            'quantity' => 0,
            'before' => json_encode([], JSON_THROW_ON_ERROR),
            'after' => json_encode([], JSON_THROW_ON_ERROR),
            'occurred_at' => now()->addSecond(),
            'created_by' => auth()->id(),
            'created_at' => now()->addSecond(),
        ]);

        // Request: rechaza la corrección y mantiene intacto el lote finalizado.
        $this->command('PATCH', '/mortalities/'.$terminal->json('data.mortality.id'), [
            'version' => 1, 'flock_version' => 2, 'quantity' => 1,
            'confirm_reactivation' => true, 'reason' => 'Operación terminal reutilizada',
        ])->assertConflict();
        $this->assertSame(FlockStatus::Finished, $flock->fresh()->status);
        $this->assertSame(0, $flock->fresh()->current_quantity);
        $this->assertDatabaseHas('mortality_records', ['version' => 1, 'status' => 'recorded']);
        $this->assertDatabaseCount('flock_movements', 2);
    }

    // Flujo: impide superar la cantidad viva y usar categorías inactivas.
    public function test_negative_balance_and_inactive_category_are_rejected(): void
    {
        // Preparación: configura un lote de diez aves.
        $this->signIn();
        $flock = $this->flock(10);
        $category = MortalityCategory::factory()->create();
        $payload = ['version' => 1, 'quantity' => 11, 'mortality_category_id' => $category->id];

        // Requests: comprueba cantidades y vigencia del catálogo sin efectos parciales.
        $this->command('POST', "/flocks/{$flock->public_id}/mortalities", $payload)->assertConflict();
        $category->forceFill(['status' => 'inactive'])->save();
        $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [...$payload, 'quantity' => 1])->assertConflict();
        $this->assertDatabaseCount('mortality_records', 0);
        $this->assertSame(10, $flock->fresh()->current_quantity);
    }

    // Flujo: una cancelación no puede sobreocupar el galpón si otra admisión usó las plazas.
    public function test_cancellation_rechecks_capacity_and_keeps_original_record_on_conflict(): void
    {
        // Preparación: agota el lote y deja el galpón ocupado por un lote nuevo.
        $this->signIn();
        $house = PoultryHouse::factory()->create(['bird_capacity' => 10]);
        $flock = $this->flock(10, house: $house);
        $category = MortalityCategory::factory()->create();
        $created = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 10, 'mortality_category_id' => $category->id,
        ])->assertCreated();
        $this->flock(2, house: $house);

        // Request: no reactiva ni restituye aves cuando el galpón ya está ocupado.
        $this->command('POST', '/mortalities/'.$created->json('data.mortality.id').'/cancellation', [
            'version' => 1, 'flock_version' => 2, 'confirm_reactivation' => true, 'reason' => 'Corrección sin plazas disponibles',
        ])->assertConflict();
        $this->assertSame(0, $flock->fresh()->current_quantity);
        $this->assertDatabaseHas('mortality_records', ['status' => 'recorded', 'version' => 1]);
        $this->assertDatabaseCount('flock_movements', 1);
    }

    // Flujo: exige versiones actuales tanto del registro como del lote.
    public function test_stale_record_or_flock_version_cannot_correct_mortality(): void
    {
        // Preparación: registra una mortalidad válida.
        $this->signIn();
        $flock = $this->flock();
        $category = MortalityCategory::factory()->create();
        $created = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 2, 'mortality_category_id' => $category->id,
        ])->assertCreated();
        $url = '/mortalities/'.$created->json('data.mortality.id');

        // Requests: rechaza cada versión desactualizada por separado.
        $this->command('PATCH', $url, ['version' => 1, 'flock_version' => 1, 'quantity' => 1, 'reason' => 'Versión anterior'])->assertConflict();
        $this->command('PATCH', $url, ['version' => 2, 'flock_version' => 2, 'quantity' => 1, 'reason' => 'Versión incorrecta'])->assertConflict();
        $this->assertSame(98, $flock->fresh()->current_quantity);
    }

    // Flujo: las consultas históricas conservan el galpón donde ocurrió la mortalidad.
    public function test_filters_use_event_location_and_local_calendar_date(): void
    {
        // Preparación: fija fecha y registra una baja antes de trasladar el lote.
        $this->travelTo(now()->setDate(2026, 8, 25)->setTime(15, 0));
        $this->signIn();
        $flock = $this->flock(10);
        $oldHouse = $flock->poultry_house_id;
        $category = MortalityCategory::factory()->create();
        $created = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 1, 'mortality_category_id' => $category->id, 'occurred_at' => '2026-08-25T01:00:00+00:00',
        ])->assertCreated();
        $newHouse = PoultryHouse::factory()->create();
        $this->command('POST', "/flocks/{$flock->public_id}/redistributions", [
            'version' => 2, 'quantity' => 9, 'destination_poultry_house_id' => $newHouse->id,
        ])->assertCreated();

        // Consultas: a las 01 UTC todavía es el día anterior en Montevideo.
        $this->getJson("/api/v1/mortalities?flock_id={$flock->public_id}&poultry_house_id={$oldHouse}&date_from=2026-08-24&date_to=2026-08-24")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $created->json('data.mortality.id'));
        $this->getJson("/api/v1/mortalities?poultry_house_id={$newHouse->id}")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/mortalities?date_from=2026-08-25&date_to=2026-08-24')->assertUnprocessable();
    }

    // Flujo: no acepta hechos futuros ni anteriores a la presencia del lote.
    public function test_occurrence_must_respect_flock_timeline(): void
    {
        // Preparación: crea un lote vigente con fecha inicial conocida.
        $this->signIn();
        $flock = $this->flock();
        $category = MortalityCategory::factory()->create();
        $payload = ['version' => 1, 'quantity' => 1, 'mortality_category_id' => $category->id];

        // Requests: rechaza ambos extremos incompatibles de la línea temporal.
        $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [...$payload, 'occurred_at' => now()->addDay()->toIso8601String()])->assertConflict();
        $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [...$payload, 'occurred_at' => $flock->established_at->subDay()->toIso8601String()])->assertConflict();
        $this->assertDatabaseCount('mortality_records', 0);
    }

    // Flujo: el reintento offline no registra nuevamente una baja ya aplicada.
    public function test_replay_does_not_double_mortality(): void
    {
        // Preparación: conserva la clave del comando original.
        $this->signIn();
        $flock = $this->flock(10);
        $category = MortalityCategory::factory()->create();
        $payload = ['version' => 1, 'quantity' => 2, 'mortality_category_id' => $category->id];
        $key = (string) Str::uuid();

        // Requests: ambos envíos obtienen la misma respuesta persistida.
        $first = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", $payload, $key)->assertCreated();
        $second = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", $payload, $key)->assertCreated();
        $this->assertSame($first->json(), $second->json());
        $this->assertSame(8, $flock->fresh()->current_quantity);
        $this->assertDatabaseCount('mortality_records', 1);
    }

    // Flujo: una mortalidad cancelada no puede cancelarse otra vez con otra operación.
    public function test_cancelled_or_finished_records_cannot_be_corrected(): void
    {
        // Preparación: registra y cancela una mortalidad.
        $this->signIn();
        $flock = $this->flock();
        $category = MortalityCategory::factory()->create();
        $created = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 1, 'mortality_category_id' => $category->id,
        ])->assertCreated();
        $url = '/mortalities/'.$created->json('data.mortality.id').'/cancellation';
        $this->command('POST', $url, ['version' => 1, 'flock_version' => 2, 'reason' => 'Error'])->assertOk();

        // Requests: no duplica la compensación ni permite registrar en un lote finalizado.
        $this->command('POST', $url, ['version' => 2, 'flock_version' => 3, 'reason' => 'Otra cancelación'])->assertConflict();
        $this->command('POST', "/flocks/{$flock->public_id}/finalization", ['version' => 3, 'reason' => 'Fin de ciclo'])->assertOk();
        $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 4, 'quantity' => 1, 'mortality_category_id' => $category->id,
        ])->assertConflict();
        $this->assertDatabaseCount('mortality_records', 1);
    }
}
