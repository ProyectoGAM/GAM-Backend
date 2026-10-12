<?php

namespace Tests\Feature\Lots;

use App\Enums\Lots\FlockStatus;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Interfaces\FarmStructure\PoultryHouseOccupancyProvider;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockMovement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

final class MortalityEndpointTest extends LotsTestCase
{
    // Flujo: registra, corrige y cancela mortalidad sin reescribir el historial.
    public function test_corrections_restore_live_quantity_and_append_audit_history(): void
    {
        // Preparación: configura un lote vigente.
        $this->signIn();
        $flock = $this->flock(100);

        // Request: registra cinco bajas y conserva la cantidad inicial.
        $created = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 5, 'notes' => 'Motivo registrado', 'occurred_at' => now()->startOfSecond()->toIso8601String(),
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

    // Flujo: un lote en cuarentena conserva su estado aunque pierda todas las aves.
    public function test_quarantine_and_loss_of_all_birds_preserve_flock_status(): void
    {
        // Preparación: crea un lote pequeño en cuarentena.
        $this->signIn();
        $flock = Flock::factory()->quarantined()->create(['initial_quantity' => 3, 'current_quantity' => 3]);

        // Request: registra todas las bajas y conserva el estado del lote.
        $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 3, 'notes' => 'Motivo registrado', 'occurred_at' => now()->startOfSecond()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.flock.current_quantity', 0)->assertJsonPath('data.flock.status', 'quarantined');
        $this->assertSame(FlockStatus::Quarantined, $flock->fresh()->status);
        $this->assertNull($flock->fresh()->finalized_at);
    }

    // Flujo: exige confirmación y reactiva atómicamente un lote agotado por mortalidad.
    public function test_correction_reactivation_requires_confirmation_and_restores_status(): void
    {
        // Preparación: agota un lote activo con una mortalidad registrada.
        $this->signIn();
        $flock = $this->flock(3);
        $created = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 3, 'notes' => 'Motivo registrado', 'occurred_at' => now()->startOfSecond()->toIso8601String(),
        ])->assertCreated();
        // Preparación: representa la finalización automática histórica anterior a este cambio.
        $this->prepareLegacyMortalityTermination($flock);
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
        $created = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 3, 'notes' => 'Motivo registrado', 'occurred_at' => now()->startOfSecond()->toIso8601String(),
        ])->assertCreated();
        // Preparación: representa la finalización automática histórica anterior a este cambio.
        $this->prepareLegacyMortalityTermination($flock);
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
        $created = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 3, 'notes' => 'Motivo registrado', 'occurred_at' => now()->startOfSecond()->toIso8601String(),
        ])->assertCreated();
        // Preparación: representa la finalización automática histórica anterior a este cambio.
        $this->prepareLegacyMortalityTermination($flock);
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
        $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 1, 'notes' => 'Motivo registrado', 'occurred_at' => now()->startOfSecond()->toIso8601String(),
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
            'version' => 2, 'quantity' => 2, 'notes' => 'Motivo registrado', 'occurred_at' => now()->startOfSecond()->toIso8601String(),
        ])->assertCreated();
        // Preparación: representa la finalización automática histórica anterior a este cambio.
        $this->prepareLegacyMortalityTermination($flock);

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
        $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 1, 'notes' => 'Motivo registrado', 'occurred_at' => now()->startOfSecond()->toIso8601String(),
        ])->assertCreated();
        $previousOperationId = (string) FlockMovement::query()
            ->where('source_flock_id', $flock->id)
            ->where('type', 'mortality')
            ->orderBy('id')
            ->value('operation_id');
        $this->assertNotSame('', $previousOperationId);
        $terminal = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 2, 'quantity' => 2, 'notes' => 'Motivo registrado', 'occurred_at' => now()->startOfSecond()->toIso8601String(),
        ])->assertCreated();
        // Preparación: representa la finalización automática histórica anterior a este cambio.
        $this->prepareLegacyMortalityTermination($flock);

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
        $terminal = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 3, 'notes' => 'Motivo registrado', 'occurred_at' => now()->startOfSecond()->toIso8601String(),
        ])->assertCreated();
        // Preparación: representa la finalización automática histórica anterior a este cambio.
        $this->prepareLegacyMortalityTermination($flock);
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

    // Flujo: el campo retirado de categoría se rechaza sin modificar el lote.
    public function test_removed_category_field_is_rejected(): void
    {
        // Preparación: configura un lote de diez aves.
        $this->signIn();
        $flock = $this->flock(10);
        $payload = ['version' => 1, 'quantity' => 11, 'notes' => 'Motivo registrado', 'occurred_at' => now()->startOfSecond()->toIso8601String()];

        // Request: informa que la categoría ya no forma parte del contrato.
        $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [...$payload, 'mortality_category_id' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['mortality_category_id']);
        $this->assertDatabaseCount('mortality_records', 0);
        $this->assertSame(10, $flock->fresh()->current_quantity);
    }

    // Flujo: el catálogo retirado ya no expone consultas ni altas.
    public function test_removed_category_catalog_is_unavailable(): void
    {
        // Preparación: autentica un operador de mortalidad.
        $this->signIn();

        // Requests: las rutas retiradas no se resuelven.
        $this->getJson('/api/v1/mortality-categories')->assertNotFound();
        $this->command('POST', '/mortality-categories', ['name' => 'Categoría retirada'])->assertNotFound();
    }

    // Flujo: mortalidad conserva la autenticación y los permisos funcionales para toda escritura.
    public function test_writes_require_authentication_and_mortality_management_permission(): void
    {
        // Preparación: conserva un lote vigente y un comando válido.
        $flock = $this->flock();
        $payload = ['version' => 1, 'quantity' => 1, 'notes' => 'Baja observada', 'occurred_at' => now()->startOfSecond()->toIso8601String()];

        // Request: un usuario sin autenticar no puede registrar mortalidad.
        $this->command('POST', "/flocks/{$flock->public_id}/mortalities", $payload)->assertUnauthorized();
        $this->signIn();
        $created = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", $payload)->assertCreated();
        $this->signIn(['mortality.view']);

        // Requests: el permiso de lectura no habilita altas, correcciones ni cancelaciones.
        $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [...$payload, 'version' => 2])->assertForbidden();
        $path = '/mortalities/'.$created->json('data.mortality.id');
        $correction = ['version' => 1, 'flock_version' => 2, 'reason' => 'Revisión'];
        $this->command('PATCH', $path, [...$correction, 'quantity' => 2])->assertForbidden();
        $this->command('POST', $path.'/cancellation', $correction)->assertForbidden();
        $this->assertDatabaseCount('mortality_records', 1);
        $this->assertDatabaseCount('activity_log', 2);
        $this->assertSame(99, $flock->fresh()->current_quantity);
    }

    // Flujo: registra más bajas que aves y revierte el saldo negativo sin exigir capacidad para aves inexistentes.
    public function test_excess_mortality_can_be_cancelled_with_audit_and_idempotency(): void
    {
        // Preparación: fija el instante y la capacidad exacta del lote de dos mil aves.
        $this->freezeTime();
        $actor = $this->signIn();
        $house = PoultryHouse::factory()->create(['bird_capacity' => 2000]);
        $flock = $this->flock(2000, house: $house);
        $payload = ['version' => 1, 'quantity' => 2001, 'notes' => 'Recuento de bajas', 'occurred_at' => now()->startOfSecond()->toIso8601String()];
        $key = (string) Str::uuid();

        // Requests: registra y reintenta la misma mortalidad sin categoría ni bajas duplicadas.
        $created = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", $payload, $key)
            ->assertCreated()->assertJsonPath('data.flock.current_quantity', -1)
            ->assertJsonPath('data.flock.status', 'active')->assertJsonMissingPath('data.mortality.mortality_category_id');
        $this->assertSame($created->json(), $this->command('POST', "/flocks/{$flock->public_id}/mortalities", $payload, $key)->assertCreated()->json());
        $this->assertDatabaseHas('flocks', ['id' => $flock->id, 'current_quantity' => -1, 'initial_quantity' => 2000]);
        $this->assertDatabaseHas('mortality_records', ['quantity' => 2001, 'notes' => 'Recuento de bajas', 'created_by' => $actor->id]);
        $originalMovement = FlockMovement::query()->firstOrFail()->getAttributes();
        $originalAudits = DB::table('activity_log')->orderBy('id')->get()->toArray();
        $this->assertDatabaseHas('activity_log', ['event' => 'mortality_recorded', 'operation_id' => $created->json('data.operation_id'), 'causer_id' => $actor->id]);

        // Request: revierte con capacidad para las dos mil aves realmente restituidas.
        $url = '/mortalities/'.$created->json('data.mortality.id').'/cancellation';
        $cancel = ['version' => 1, 'flock_version' => 2, 'reason' => 'Error de recuento'];
        $this->command('POST', $url, $cancel)->assertOk()
            ->assertJsonPath('data.flock.current_quantity', 2000)->assertJsonPath('data.flock.status', 'active')
            ->assertJsonPath('data.mortality.status', 'cancelled');
        $this->assertDatabaseCount('mortality_records', 1);
        $this->assertSame($originalMovement, FlockMovement::query()->orderBy('id')->firstOrFail()->getAttributes());
        $this->assertEquals($originalAudits, DB::table('activity_log')->orderBy('id')->limit(count($originalAudits))->get()->toArray());
        $this->assertDatabaseHas('activity_log', ['event' => 'mortality_cancelled']);
        $this->assertSame(2000, $house->fresh()->bird_capacity);
    }

    // Flujo: corrige un registro cruzando cero sin cambiar la cuarentena.
    public function test_corrections_can_cross_zero_and_preserve_flock_status(): void
    {
        // Preparación: configura un lote de diez aves en cuarentena.
        $this->signIn();
        $flock = Flock::factory()->quarantined()->create(['initial_quantity' => 10, 'current_quantity' => 10]);
        $created = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 2, 'notes' => 'Bajas observadas', 'occurred_at' => now()->startOfSecond()->toIso8601String(),
        ])->assertCreated();
        $url = '/mortalities/'.$created->json('data.mortality.id');

        // Requests: aumenta las bajas y reduce el déficit conservando la cuarentena.
        $this->command('PATCH', $url, ['version' => 1, 'flock_version' => 2, 'quantity' => 12, 'reason' => 'Recuento completo'])
            ->assertOk()->assertJsonPath('data.flock.current_quantity', -2)->assertJsonPath('data.flock.status', 'quarantined');
        $this->command('PATCH', $url, ['version' => 2, 'flock_version' => 3, 'quantity' => 11, 'reason' => 'Recuento rectificado'])
            ->assertOk()->assertJsonPath('data.flock.current_quantity', -1)->assertJsonPath('data.flock.finalized_at', null);
        $this->command('PATCH', $url, ['version' => 3, 'flock_version' => 4, 'notes' => 'Causa verificada', 'reason' => 'Detalle de causa'])
            ->assertOk()->assertJsonPath('data.flock.current_quantity', -1);

        // Request: devuelve aves desde saldo negativo conservando la cuarentena.
        $this->command('PATCH', $url, ['version' => 4, 'flock_version' => 5, 'quantity' => 9, 'reason' => 'Recuento final'])
            ->assertOk()->assertJsonPath('data.flock.current_quantity', 1)->assertJsonPath('data.flock.status', 'quarantined');
        $this->assertDatabaseHas('mortality_records', ['quantity' => 9, 'notes' => 'Causa verificada', 'version' => 5]);
        $this->assertNull($flock->fresh()->finalized_at);
    }

    // Flujo: el alta exige fecha, cantidad y motivo.
    public function test_date_quantity_and_text_reason_are_required(): void
    {
        // Preparación: crea un lote para validar el contrato mínimo.
        $this->signIn();
        $flock = $this->flock();

        // Request: la ausencia de datos se informa antes de persistir cualquier cambio.
        $this->command('POST', "/flocks/{$flock->public_id}/mortalities", ['version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['quantity', 'occurred_at', 'notes'])
            ->assertJsonPath('errors.notes.0', 'Debes indicar el motivo de la mortalidad.')
            ->assertJsonPath('errors.occurred_at.0', 'Debes indicar la fecha de la mortalidad.');
        $this->assertDatabaseCount('mortality_records', 0);
        $this->assertDatabaseCount('activity_log', 0);
    }

    #[TestWith([0])]
    #[TestWith([-1])]
    #[TestWith([1.5])]
    // Flujo: rechaza cantidades no positivas o fraccionarias tanto al registrar como al editar.
    public function test_invalid_quantities_are_rejected_on_creation_and_correction(int|float $quantity): void
    {
        // Preparación: registra una baja válida antes de intentar los valores inválidos.
        $this->signIn();
        $flock = $this->flock();
        $payload = ['version' => 1, 'quantity' => 1, 'notes' => 'Baja observada', 'occurred_at' => now()->startOfSecond()->toIso8601String()];
        $created = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", $payload)->assertCreated();

        // Requests: rechaza ambos comandos sin nuevas bajas ni auditorías.
        $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [...$payload, 'version' => 2, 'quantity' => $quantity])
            ->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
        $this->command('PATCH', '/mortalities/'.$created->json('data.mortality.id'), ['version' => 1, 'flock_version' => 2, 'quantity' => $quantity, 'reason' => 'Corrección'])
            ->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
        $this->assertDatabaseCount('mortality_records', 1);
        $this->assertDatabaseCount('activity_log', 2);
        $this->assertSame(99, $flock->fresh()->current_quantity);
    }

    #[TestWith([null])]
    #[TestWith([''])]
    #[TestWith(['   '])]
    #[TestWith([123])]
    // Flujo: no admite motivos vacíos ni valores que no sean texto al registrar o corregir.
    public function test_invalid_text_reasons_are_rejected_on_creation_and_correction(mixed $notes): void
    {
        // Preparación: conserva un motivo válido para comprobar que no se borre.
        $this->signIn();
        $flock = $this->flock();
        $payload = ['version' => 1, 'quantity' => 1, 'notes' => 'Baja observada', 'occurred_at' => now()->startOfSecond()->toIso8601String()];
        $created = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", $payload)->assertCreated();

        // Requests: valida el texto antes de modificar el registro o su saldo.
        $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [...$payload, 'version' => 2, 'notes' => $notes])
            ->assertUnprocessable()->assertJsonValidationErrors(['notes']);
        $this->command('PATCH', '/mortalities/'.$created->json('data.mortality.id'), ['version' => 1, 'flock_version' => 2, 'notes' => $notes, 'reason' => 'Corrección'])
            ->assertUnprocessable()->assertJsonValidationErrors(['notes']);
        $this->assertDatabaseHas('mortality_records', ['notes' => 'Baja observada', 'version' => 1]);
        $this->assertDatabaseCount('activity_log', 2);
    }

    // Flujo: una cancelación no puede sobreocupar el galpón si otra admisión usó las plazas.
    public function test_cancellation_rechecks_capacity_and_keeps_original_record_on_conflict(): void
    {
        // Preparación: agota el lote y deja el galpón ocupado por un lote nuevo.
        $this->signIn();
        $house = PoultryHouse::factory()->create(['bird_capacity' => 10]);
        $flock = $this->flock(10, house: $house);
        $created = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 10, 'notes' => 'Motivo registrado', 'occurred_at' => now()->startOfSecond()->toIso8601String(),
        ])->assertCreated();
        // Preparación: representa la finalización automática histórica anterior a este cambio.
        $this->prepareLegacyMortalityTermination($flock);
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
        $created = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 2, 'notes' => 'Motivo registrado', 'occurred_at' => now()->startOfSecond()->toIso8601String(),
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
        $created = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 1, 'notes' => 'Motivo registrado', 'occurred_at' => '2026-08-25T01:00:00+00:00',
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
        $payload = ['version' => 1, 'quantity' => 1, 'notes' => 'Motivo registrado', 'occurred_at' => now()->startOfSecond()->toIso8601String()];

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
        $payload = ['version' => 1, 'quantity' => 2, 'notes' => 'Motivo registrado', 'occurred_at' => now()->startOfSecond()->toIso8601String()];
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
        $created = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 1, 'notes' => 'Motivo registrado', 'occurred_at' => now()->startOfSecond()->toIso8601String(),
        ])->assertCreated();
        $url = '/mortalities/'.$created->json('data.mortality.id').'/cancellation';
        $this->command('POST', $url, ['version' => 1, 'flock_version' => 2, 'reason' => 'Error'])->assertOk();

        // Requests: no duplica la compensación ni permite registrar en un lote finalizado.
        $this->command('POST', $url, ['version' => 2, 'flock_version' => 3, 'reason' => 'Otra cancelación'])->assertConflict();
        $this->command('POST', "/flocks/{$flock->public_id}/finalization", ['version' => 3, 'reason' => 'Fin de ciclo'])->assertOk();
        $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 4, 'quantity' => 1, 'notes' => 'Motivo registrado', 'occurred_at' => now()->startOfSecond()->toIso8601String(),
        ])->assertConflict();
        $this->assertDatabaseCount('mortality_records', 1);
    }

    // Flujo: los saldos negativos no crean plazas adicionales y el galpón sigue reservado.
    public function test_negative_balance_has_zero_physical_occupancy_until_explicit_finalization(): void
    {
        // Preparación: registra un déficit en un galpón de diez plazas.
        $this->signIn();
        $house = PoultryHouse::factory()->create(['bird_capacity' => 10]);
        $flock = $this->flock(10, house: $house);
        $created = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 11, 'notes' => 'Recuento de bajas', 'occurred_at' => now()->startOfSecond()->toIso8601String(),
        ])->assertCreated();
        $occupancy = $this->app->make(PoultryHouseOccupancyProvider::class);

        // Consultas: hay cero aves físicas, pero se conserva un lote abierto en el galpón.
        $this->assertSame(0, $occupancy->occupancyFor($house->id));
        $this->assertSame([$house->id => 0], $occupancy->occupanciesFor([$house->id]));
        $this->assertSame(1, $occupancy->openFlocksCountFor($house->id));

        // Request: la finalización explícita pone el saldo en cero sin generar un egreso negativo.
        $finalized = $this->command('POST', "/flocks/{$flock->public_id}/finalization", ['version' => 2, 'reason' => 'Fin de ciclo'])
            ->assertOk()->assertJsonPath('data.flock.current_quantity', 0)->assertJsonPath('data.flock.status', 'finished')
            ->assertJsonPath('data.movement.quantity', 0)->assertJsonPath('data.movement.before.'.$flock->public_id.'.current_quantity', -1);
        $audit = DB::table('activity_log')->where('operation_id', $finalized->json('data.operation_id'))->where('event', 'flock_finalized')->first();
        $this->assertSame(-1, json_decode($audit->attribute_changes, true, flags: JSON_THROW_ON_ERROR)['old']['current_quantity']);
        $this->assertSame(0, $occupancy->openFlocksCountFor($house->id));

        // Request: la cancelación de mortalidad no reabre una finalización explícita posterior.
        $this->command('POST', '/mortalities/'.$created->json('data.mortality.id').'/cancellation', [
            'version' => 1, 'flock_version' => 3, 'reason' => 'Revisión posterior', 'confirm_reactivation' => true,
        ])->assertConflict();
        $this->assertDatabaseHas('mortality_records', ['status' => 'recorded', 'version' => 1]);
    }

    // Flujo: puede seguir registrando mortalidad con saldo negativo sin desbordar el entero anterior.
    public function test_multiple_mortalities_can_accumulate_a_negative_balance(): void
    {
        // Preparación: abre un lote pequeño que conservará su estado.
        $this->signIn();
        $flock = $this->flock(10);
        $payload = ['quantity' => 2147483647, 'notes' => 'Recuento informado', 'occurred_at' => now()->startOfSecond()->toIso8601String()];

        // Requests: las dos bajas acumuladas caben en el saldo ampliado de la base.
        $this->command('POST', "/flocks/{$flock->public_id}/mortalities", ['version' => 1, ...$payload])->assertCreated();
        $this->command('POST', "/flocks/{$flock->public_id}/mortalities", ['version' => 2, ...$payload])->assertCreated()
            ->assertJsonPath('data.flock.current_quantity', -4294967284)->assertJsonPath('data.flock.status', 'active');
        $this->assertDatabaseHas('flocks', ['id' => $flock->id, 'current_quantity' => -4294967284]);
        $this->assertDatabaseCount('mortality_records', 2);
    }

    // Flujo: edita fecha y motivo conservando el movimiento original y las fotografías auditadas.
    public function test_date_and_reason_can_be_corrected_without_rewriting_original_movement(): void
    {
        // Preparación: fija una línea temporal y registra una baja anterior al traslado.
        $this->travelTo(CarbonImmutable::parse('2026-10-12T12:00:00Z'));
        $this->signIn();
        $flock = $this->flock(10);
        $originalHouseId = $flock->poultry_house_id;
        $created = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 1, 'notes' => 'Causa inicial', 'occurred_at' => '2026-10-10T12:00:00Z',
        ])->assertCreated();
        $originalMovement = FlockMovement::query()->firstOrFail()->getAttributes();
        $destination = PoultryHouse::factory()->create();
        $this->command('POST', "/flocks/{$flock->public_id}/redistributions", [
            'version' => 2, 'quantity' => 9, 'destination_poultry_house_id' => $destination->id,
        ])->assertCreated();

        // Request: permite una fecha anterior dentro del mismo período de galpón y corrige el texto.
        $corrected = $this->command('PATCH', '/mortalities/'.$created->json('data.mortality.id'), [
            'version' => 1, 'flock_version' => 3, 'occurred_at' => '2026-10-09T12:00:00Z', 'notes' => 'Causa verificada', 'reason' => 'Rectificación de la libreta',
        ])->assertOk()->assertJsonPath('data.mortality.occurred_at', '2026-10-09T12:00:00+00:00')
            ->assertJsonPath('data.mortality.notes', 'Causa verificada')->assertJsonPath('data.mortality.poultry_house_id', $originalHouseId)
            ->assertJsonPath('data.movement.quantity', 0)->assertJsonPath('data.flock.current_quantity', 9);
        $this->assertSame($originalMovement, FlockMovement::query()->orderBy('id')->firstOrFail()->getAttributes());
        $audit = DB::table('activity_log')->where('event', 'mortality_corrected')->where('operation_id', $corrected->json('data.operation_id'))->first();
        $changes = json_decode($audit->attribute_changes, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Causa inicial', $changes['old']['notes']);
        $this->assertSame('Causa verificada', $changes['new']['notes']);
        $this->assertSame('2026-10-10T12:00:00+00:00', CarbonImmutable::parse($changes['old']['occurred_at'])->utc()->toIso8601String());

        // Requests: no acepta fechas futuras, anteriores al lote o en el galpón posterior.
        foreach (['2026-10-13T12:00:00Z', $flock->established_at->subDay()->toIso8601String(), '2026-10-12T12:00:00Z'] as $invalidTime) {
            $this->command('PATCH', '/mortalities/'.$created->json('data.mortality.id'), [
                'version' => 2, 'flock_version' => 4, 'occurred_at' => $invalidTime, 'reason' => 'Fecha incompatible',
            ])->assertConflict();
        }
        $this->assertDatabaseHas('mortality_records', ['notes' => 'Causa verificada', 'version' => 2]);
    }

    // Flujo: una falla de auditoría revierte conjuntamente la baja y todas sus escrituras.
    public function test_audit_failure_rolls_back_mortality_and_balance(): void
    {
        // Preparación: simula una falla del almacenamiento de auditoría.
        $this->signIn();
        $flock = $this->flock(10);
        $this->mock(AuditRecorder::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Falla de auditoría simulada'));

        // Request: el registro completo falla dentro de la transacción de negocio.
        $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 11, 'notes' => 'Recuento de bajas', 'occurred_at' => now()->startOfSecond()->toIso8601String(),
        ])->assertInternalServerError();
        $this->assertSame(10, $flock->fresh()->current_quantity);
        $this->assertDatabaseCount('mortality_records', 0);
        $this->assertDatabaseCount('flock_movements', 0);
        $this->assertDatabaseCount('flock_activities', 0);
        $this->assertDatabaseCount('activity_log', 0);
    }

    /** Prepara una fotografía de terminalidad heredada para conservar la cobertura de datos previos. */
    private function prepareLegacyMortalityTermination(Flock $flock): void
    {
        $flock->refresh()->forceFill(['status' => FlockStatus::Finished, 'finalized_at' => now(), 'finalization_reason' => 'Lote agotado por mortalidad.'])->save();
        $movement = FlockMovement::query()->where('source_flock_id', $flock->id)->orderByDesc('id')->firstOrFail();
        $after = $movement->after;
        $after[$flock->public_id]['status'] = 'finished';
        $after[$flock->public_id]['finalized_at'] = $flock->finalized_at->toIso8601String();
        $after[$flock->public_id]['finalization_reason'] = $flock->finalization_reason;
        $movement->forceFill(['after' => $after])->save();
    }
}
