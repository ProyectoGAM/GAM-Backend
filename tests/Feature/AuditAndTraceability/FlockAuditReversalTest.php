<?php

namespace Tests\Feature\AuditAndTraceability;

use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockMovement;
use App\Models\Lots\WeighingReferenceSettings;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Lots\LotsTestCase;

final class FlockAuditReversalTest extends LotsTestCase
{
    // Flujo: revierte el último cambio con la versión vigente y conserva el vínculo en auditoría.
    public function test_status_reversal_links_the_entry_and_replays_the_original_result(): void
    {
        // Preparación: registra la cuarentena y abre el detalle.
        $this->signIn(['can_view_audit', 'can_manage_flocks']);
        $flock = $this->flock();
        $url = "/flocks/{$flock->public_id}/status";
        $entry = $this->changeStatus($flock, 1, 'quarantined');
        $original = $entry->getAttributes();
        $this->detail($entry)->assertJsonPath('data.presentation.actions.0.target.version', 2);

        // Mutación y consulta: las observaciones invalidan la versión anterior, pero el cambio de estado sigue vigente.
        $payload = ['status' => 'active', 'version' => 2, 'reason' => 'Registro equivocado', 'audit_entry_id' => $entry->id];
        $this->command('PATCH', "/flocks/{$flock->public_id}", ['version' => 2, 'notes' => 'Observación posterior'])->assertOk();
        $this->command('PATCH', $url, $payload)->assertConflict()
            ->assertJsonPath('detail', 'La versión del lote cambió. Actualiza los datos antes de reintentar.');
        $this->detail($entry)->assertJsonPath('data.presentation.reversibility.state', 'available')
            ->assertJsonPath('data.presentation.actions.0.kind', 'reverse_flock_status')
            ->assertJsonPath('data.presentation.actions.0.target', ['resource' => 'flock', 'id' => $flock->public_id, 'status' => 'active', 'version' => 3]);

        // Requests y consulta: una misma confirmación produce una sola compensación.
        $payload['version'] = 3;
        $key = (string) Str::uuid();
        $result = $this->command('PATCH', $url, $payload, $key)->assertOk()
            ->assertJsonPath('data.flock.status', 'active')->assertJsonPath('data.flock.version', 4);
        $this->command('PATCH', $url, $payload, $key)->assertOk()->assertExactJson($result->json());
        $compensation = AuditEntry::query()->forOperation($result->json('data.operation_id'))->sole();
        $this->assertSame($entry->id, $compensation->properties->get('reverses_audit_entry_id'));
        $this->assertSame($original, $entry->refresh()->getAttributes());
        $this->assertSame(2, AuditEntry::query()->where('event', 'flock_status_changed')->count());
        $this->detail($entry)->assertJsonPath('data.presentation.actions.0.available', false);
    }

    // Flujo: una coincidencia de estado no vuelve a habilitar una transición desplazada.
    public function test_cycled_status_and_an_entry_for_another_flock_are_rejected_with_409(): void
    {
        // Preparación: repite la misma transición y crea una entrada de otro lote.
        $this->signIn(['can_view_audit', 'can_manage_flocks']);
        $flock = $this->flock();
        $old = $this->changeStatus($flock, 1, 'quarantined');
        $this->changeStatus($flock, 2, 'active');
        $latest = $this->changeStatus($flock, 3, 'quarantined');
        $foreign = $this->changeStatus($this->flock(), 1, 'quarantined');

        // Consulta y requests: la última transición es la única elegible, incluso con la versión actual en los intentos inválidos.
        $this->detail($latest)->assertJsonPath('data.presentation.actions.0.available', true);
        $this->detail($old)->assertJsonPath('data.presentation.actions.0.reason', 'Este cambio de estado ya no está vigente.')
            ->assertJsonMissingPath('data.presentation.actions.0.target.version');
        $url = "/flocks/{$flock->public_id}/status";
        $payload = ['status' => 'active', 'version' => 4, 'reason' => 'Revisar referencia'];
        $this->command('PATCH', $url, [...$payload, 'audit_entry_id' => $old->id])->assertConflict()
            ->assertJsonPath('detail', 'Este cambio de estado ya no está vigente.');
        $this->command('PATCH', $url, [...$payload, 'audit_entry_id' => $foreign->id])->assertConflict()
            ->assertJsonPath('detail', 'No se encontró un cambio de estado verificable para este lote.');
        $this->command('PATCH', $url, [...$payload, 'status' => 'quarantined', 'audit_entry_id' => $latest->id])->assertConflict()
            ->assertJsonPath('detail', 'El estado solicitado no coincide con el estado anterior de la entrada.');
        $this->assertSame(4, $flock->refresh()->version);
    }

    // Flujo: distingue permisos, historial incompleto y finalización explícita.
    public function test_status_reversal_explains_unavailability_and_finalization(): void
    {
        // Preparación: crea un cambio legítimo y un registro legado sin estados.
        $this->signIn(['can_view_audit', 'can_manage_flocks', 'can_finalize_flocks']);
        $flock = $this->flock();
        $entry = $this->changeStatus($flock, 1, 'quarantined');
        $legacy = AuditEntry::query()->create([
            'event' => 'flock_status_changed', 'log_name' => 'lots', 'description' => 'Cambio legado',
            'subject_type' => $flock->getMorphClass(), 'subject_id' => $flock->id, 'up_id' => $flock->production_unit_id,
        ]);
        $this->detail($legacy)->assertJsonPath('data.presentation.reversibility.state', 'unavailable')
            ->assertJsonPath('data.presentation.actions.0.reason', 'La entrada no conserva una transición verificable entre Activo y En cuarentena.')
            ->assertJsonMissingPath('data.presentation.actions.0.target.status');
        $withoutSubject = AuditEntry::query()->create([
            'event' => 'flock_status_changed', 'log_name' => 'lots', 'description' => 'Cambio sin referencia',
        ]);
        $this->detail($withoutSubject)->assertJsonPath('data.presentation.reversibility.state', 'unavailable')
            ->assertJsonPath('data.presentation.actions', []);

        // Consulta y request: el permiso de lectura de Auditoría y el permiso de escritura se comprueban por separado.
        $this->signIn(['can_view_audit']);
        $this->detail($entry)->assertJsonPath('data.presentation.actions.0.available', false)
            ->assertJsonPath('data.presentation.actions.0.reason', 'No tienes permiso para revertir cambios de estado de lotes.');
        $this->signIn(['can_manage_flocks']);
        $this->command('PATCH', "/flocks/{$flock->public_id}/status", [
            'status' => 'active', 'version' => 2, 'reason' => 'Sin acceso al historial', 'audit_entry_id' => $entry->id,
        ])->assertForbidden();

        // Mutación y consulta: la finalización y los cambios anteriores de ese lote aparecen como irreversibles.
        $this->signIn(['can_view_audit', 'can_manage_flocks', 'can_finalize_flocks']);
        $finalization = $this->command('POST', "/flocks/{$flock->public_id}/finalization", [
            'version' => 2, 'reason' => 'Fin del ciclo',
        ])->assertOk();
        $finalized = AuditEntry::query()->forOperation($finalization->json('data.operation_id'))->where('event', 'flock_finalized')->sole();
        foreach ([$entry, $finalized] as $historical) {
            $this->detail($historical)->assertJsonPath('data.presentation.reversibility.state', 'irreversible')
                ->assertJsonPath('data.presentation.actions', []);
        }
    }

    // Flujo: usa los destinos del detalle para compensar los cuatro tipos de redistribución.
    #[DataProvider('redistributions')]
    public function test_original_flock_entries_share_a_reversible_movement(string $type, int $quantity, ?int $destinationVersion): void
    {
        // Preparación: crea los lotes y galpones de una misma UP y configura el caso que supera la capacidad al retornar.
        $this->signIn(['can_view_audit', 'can_redistribute_flocks', 'can_view_poultry_houses']);
        $source = $type === 'partial_new' ? $this->flockWithPlan() : $this->flock();
        $house = PoultryHouse::factory()->create(['production_unit_id' => $source->production_unit_id, 'bird_capacity' => 200]);
        $payload = match ($type) {
            'partial_new' => ['destination_poultry_house_id' => $house->id, 'destination_code' => 'RECEPTOR'],
            'total' => ['destination_poultry_house_id' => $house->id],
            default => ['destination_flock_id' => $this->flock(20, $source->breed, $house)->public_id, 'destination_version' => 1],
        };
        if ($type === 'total') {
            WeighingReferenceSettings::factory()->create(['adult_from_week' => 4]);
            PoultryHouse::query()->whereKey($source->poultry_house_id)->update(['bird_capacity' => 80]);
        }

        // Mutación: registra el movimiento mediante el endpoint de Lotes.
        $redistribution = $this->command('POST', "/flocks/{$source->public_id}/redistributions", [
            'version' => 1, 'quantity' => $quantity, ...$payload,
        ])->assertCreated()->assertJsonPath('data.movement.type', $type);
        $movementId = $redistribution->json('data.movement.id');
        $entries = AuditEntry::query()->forOperation($redistribution->json('data.operation_id'))->where('event', 'flock_redistributed')->get();
        $this->assertCount($destinationVersion === null ? 1 : 2, $entries);

        // Consulta: cada entrada expone el mismo destino y las versiones de los roles originales.
        $versions = ['version' => 2, ...($destinationVersion === null ? [] : ['destination_version' => $destinationVersion])];
        foreach ($entries as $entry) {
            $this->detail($entry)->assertJsonPath('data.presentation.actions.0.kind', 'reverse_flock_redistribution')
                ->assertJsonPath('data.presentation.actions.0.label', 'Revertir redistribución')
                ->assertJsonPath('data.presentation.actions.0.available', true)
                ->assertJsonPath('data.presentation.actions.0.target', ['resource' => 'flock_redistribution', 'id' => $movementId, ...$versions]);
        }

        // Requests: revierte, reintenta con la misma clave y consulta la entrada bloqueada.
        $url = "/redistributions/{$movementId}/reversals";
        $key = (string) Str::uuid();
        $reversal = [...$versions, 'reason' => 'Devolver las aves'];
        $result = $this->command('POST', $url, $reversal, $key)->assertOk()
            ->assertJsonPath('data.flock.current_quantity', 100)
            ->assertJsonPath('data.movement.reversed_movement_id', $movementId)
            ->assertJsonMissingPath('data.warnings');
        $this->command('POST', $url, $reversal, $key)->assertOk()->assertExactJson($result->json());
        $this->assertSame(1, FlockMovement::query()->where('type', 'redistribution_reversal')->count());
        $this->detail($entries->firstOrFail())->assertJsonPath('data.presentation.actions.0.available', false)
            ->assertJsonPath('data.presentation.actions.0.reason', 'La redistribución ya fue revertida.')
            ->assertJsonPath('data.presentation.actions.0.target', ['resource' => 'flock_redistribution', 'id' => $movementId]);

        // Consulta: al superar el umbral, el sistema publica la alerta operativa de Galpones.
        if ($type === 'total') {
            $this->assertDatabaseHas('operational_alerts', [
                'type' => 'poultry_house_over_capacity', 'entity_id' => (string) $source->poultry_house_id, 'resolved_at' => null,
            ]);
        }
    }

    /** @return array<string, array{string, int, ?int}> */
    public static function redistributions(): array
    {
        return [
            'parcial a nuevo' => ['partial_new', 30, 1],
            'parcial a existente' => ['partial_existing', 30, 2],
            'traslado total' => ['total', 100, null],
            'unión total' => ['total_existing', 100, 2],
        ];
    }

    // Flujo: la reactivación por revertir una unión desplaza los cambios de estado previos.
    public function test_reopening_after_a_total_merge_does_not_revive_a_prior_status_entry(): void
    {
        // Preparación: el último PATCH dejó al origen activo y listo para unirlo.
        $this->signIn(['can_view_audit', 'can_manage_flocks', 'can_redistribute_flocks']);
        $source = $this->flock();
        $house = PoultryHouse::factory()->create(['production_unit_id' => $source->production_unit_id]);
        $receiver = $this->flock(20, $source->breed, $house);
        $this->changeStatus($source, 1, 'quarantined');
        $entry = $this->changeStatus($source, 2, 'active');

        // Mutación y consulta: la unión finaliza al origen por su propia regla.
        $merge = $this->command('POST', "/flocks/{$source->public_id}/redistributions", [
            'version' => 3, 'quantity' => 100, 'destination_flock_id' => $receiver->public_id, 'destination_version' => 1,
        ])->assertCreated();
        $this->detail($entry)->assertJsonPath('data.presentation.reversibility.state', 'irreversible');

        // Mutación y consulta: revertir la unión restaura Activo y deja atrás el PATCH histórico.
        $this->command('POST', '/redistributions/'.$merge->json('data.movement.id').'/reversals', [
            'version' => 4, 'destination_version' => 2, 'reason' => 'Unión accidental',
        ])->assertOk()->assertJsonPath('data.flock.status', 'active');
        $this->detail($entry)->assertJsonPath('data.presentation.actions.0.available', false)
            ->assertJsonPath('data.presentation.actions.0.reason', 'Este cambio de estado ya no está vigente.');
    }

    // Flujo: el detalle y el comando informan el mismo motivo al perder elegibilidad.
    #[DataProvider('redistributionRestrictions')]
    public function test_redistribution_preview_and_command_agree_on_the_blocking_reason(string $restriction, string $reason): void
    {
        // Preparación: redistribuye hacia otro lote y obtiene una confirmación todavía válida.
        $this->signIn(['can_view_audit', 'can_redistribute_flocks', 'can_manage_flocks']);
        $source = $this->flock();
        $house = PoultryHouse::factory()->create(['production_unit_id' => $source->production_unit_id]);
        $destination = $this->flock(20, $source->breed, $house);
        $operation = $this->command('POST', "/flocks/{$source->public_id}/redistributions", [
            'version' => 1, 'quantity' => 30, 'destination_flock_id' => $destination->public_id, 'destination_version' => 1,
        ])->assertCreated();
        $entry = AuditEntry::query()->forOperation($operation->json('data.operation_id'))->where('event', 'flock_redistributed')
            ->where('subject_id', $source->id)->sole();
        $this->detail($entry)->assertJsonPath('data.presentation.actions.0.available', true);
        $movement = FlockMovement::query()->where('public_id', $operation->json('data.movement.id'))->sole();

        // Mutación: cambia la condición que el comando debe volver a verificar.
        match ($restriction) {
            'actividad' => $this->command('PATCH', "/flocks/{$destination->public_id}", ['version' => 2, 'notes' => 'Actividad posterior'])->assertOk(),
            'galpón' => PoultryHouse::query()->whereKey($source->poultry_house_id)->update(['status' => 'maintenance']),
            'historial' => $movement->forceFill(['reversal_verified' => false])->save(),
        };

        // Consulta y request: el detalle omite versiones y la confirmación anterior devuelve el mismo conflicto.
        $this->detail($entry)->assertJsonPath('data.presentation.actions.0.reason', $reason)
            ->assertJsonPath('data.presentation.reversibility.state', 'unavailable')
            ->assertJsonPath('data.presentation.actions.0.target', ['resource' => 'flock_redistribution', 'id' => $movement->public_id]);
        $this->command('POST', "/redistributions/{$movement->public_id}/reversals", [
            'version' => 2, 'destination_version' => 2, 'reason' => 'Confirmación anterior',
        ])->assertConflict()->assertJsonPath('detail', $reason);
        $this->assertDatabaseCount('flock_movements', 1);
    }

    /** @return array<string, array{string, string}> */
    public static function redistributionRestrictions(): array
    {
        return [
            'actividad del receptor' => ['actividad', 'La redistribución tiene operaciones posteriores y no puede revertirse.'],
            'galpón no operativo' => ['galpón', 'El galpón destino y su unidad productiva deben estar operativos.'],
            'historial sin verificar' => ['historial', 'No se pudo comprobar de forma completa la actividad histórica de la redistribución.'],
        ];
    }

    private function changeStatus(Flock $flock, int $version, string $status): AuditEntry
    {
        $response = $this->command('PATCH', "/flocks/{$flock->public_id}/status", [
            'version' => $version, 'status' => $status, 'reason' => 'Verificación del estado',
        ])->assertOk();

        return AuditEntry::query()->forOperation($response->json('data.operation_id'))->where('event', 'flock_status_changed')->sole();
    }

    private function detail(AuditEntry $entry): TestResponse
    {
        return $this->getJson('/api/v1/auditoria/entradas/'.$entry->id)->assertOk();
    }
}
