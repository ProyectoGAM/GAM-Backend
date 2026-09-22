<?php

namespace Tests\Feature\Lots;

use App\Enums\Lots\FlockStatus;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Interfaces\FarmStructure\PoultryHouseOccupancyProvider;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\Lots\Breed;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockMovement;
use Illuminate\Support\Str;
use RuntimeException;

final class RedistributionEndpointTest extends LotsTestCase
{
    // Flujo: traslada parcialmente a un lote nuevo sin construir una genealogía.
    public function test_partial_transfer_creates_an_independent_flock_and_preserves_total(): void
    {
        // Preparación: crea origen y galpón destino con capacidad disponible.
        $this->signIn();
        $flock = $this->flock();
        $house = PoultryHouse::factory()->create(['bird_capacity' => 40]);
        $publicId = (string) Str::ulid();

        // Request: redistribuye cuarenta aves a una identidad generada offline.
        $response = $this->command('POST', "/flocks/{$flock->public_id}/redistributions", [
            'version' => 1, 'quantity' => 40, 'destination_poultry_house_id' => $house->id, 'destination_code' => 'NUEVO-40', 'destination_public_id' => $publicId,
        ])->assertCreated()->assertJsonPath('data.flock.current_quantity', 60)
            ->assertJsonPath('data.destination_flock.id', $publicId)->assertJsonPath('data.movement.type', 'partial_new');
        $destination = Flock::query()->where('public_id', $publicId)->firstOrFail();
        $this->assertSame($flock->breed_id, $destination->breed_id);
        $this->assertSame($flock->supplier_id, $destination->supplier_id);
        $this->assertSame($flock->entry_date->toDateString(), $destination->entry_date->toDateString());
        $this->assertSame(40, $destination->initial_quantity);
        $this->assertSame(100, (int) Flock::query()->sum('current_quantity'));
        $this->assertDatabaseHas('flock_movements', ['source_flock_id' => $flock->id, 'destination_flock_id' => $destination->id, 'quantity' => 40]);
        $this->assertDatabaseHas('poultry_houses', ['id' => $house->id, 'bird_capacity' => 40]);

        // Consulta: ambos lotes exponen el mismo movimiento y su fotografía histórica.
        $this->getJson("/api/v1/flocks/{$publicId}/history")->assertOk()->assertJsonPath('data.0.id', $response->json('data.movement.id'));
        $this->getJson("/api/v1/flocks/{$flock->public_id}/history")->assertOk()->assertJsonPath("data.0.before.{$flock->public_id}.current_quantity", 100);
    }

    // Flujo: agrega aves a un lote existente sin sobrescribir su procedencia ni edad.
    public function test_existing_recipient_keeps_its_metadata_and_initial_quantity(): void
    {
        // Preparación: crea lotes de igual raza pero distinto proveedor y fecha.
        $this->signIn();
        $breed = Breed::factory()->create();
        $source = $this->flock(100, $breed);
        $destination = $this->flock(20, $breed);
        $destination->forceFill(['entry_date' => now()->subDays(60)->toDateString()])->save();
        $original = $destination->only(['supplier_id', 'entry_date', 'initial_quantity', 'code']);

        // Request: incrementa el destinatario utilizando versiones de ambos lotes.
        $this->command('POST', "/flocks/{$source->public_id}/redistributions", [
            'version' => 1, 'quantity' => 30, 'destination_flock_id' => $destination->public_id, 'destination_version' => 1,
        ])->assertCreated()->assertJsonPath('data.flock.current_quantity', 70)
            ->assertJsonPath('data.destination_flock.current_quantity', 50)->assertJsonPath('data.movement.type', 'partial_existing');
        $this->assertEquals($original, $destination->fresh()->only(array_keys($original)));
        $this->assertDatabaseCount('flocks', 2);
        $this->assertSame(120, (int) Flock::query()->sum('current_quantity'));
    }

    // Flujo: mantiene la ocupación derivada por galpón al incorporar aves a otro lote.
    public function test_existing_recipient_occupancy_is_derived_per_house(): void
    {
        // Preparación: ocupa exactamente toda la capacidad física.
        $this->signIn();
        $house = PoultryHouse::factory()->create(['bird_capacity' => 100]);
        $breed = Breed::factory()->create();
        $source = $this->flock(80, $breed, $house);
        $destination = $this->flock(20, $breed);

        // Request: incorpora aves y conserva la ocupación física de cada galpón.
        $this->command('POST', "/flocks/{$source->public_id}/redistributions", [
            'version' => 1, 'quantity' => 10, 'destination_flock_id' => $destination->public_id, 'destination_version' => 1,
        ])->assertCreated();
        $this->assertSame(70, $this->app->make(PoultryHouseOccupancyProvider::class)->occupancyFor($house->id));
        $this->assertSame(30, $this->app->make(PoultryHouseOccupancyProvider::class)->occupancyFor($destination->poultry_house_id));
    }

    // Flujo: exige un galpón vacío para una división que crea un lote nuevo.
    public function test_new_split_rejects_occupied_and_same_source_houses(): void
    {
        // Preparación: ocupa un galpón destino y conserva el origen en otro.
        $this->signIn();
        $source = $this->flock();
        $occupiedHouse = PoultryHouse::factory()->create();
        $this->flock(10, $source->breed, $occupiedHouse);

        // Requests: rechaza tanto el galpón ocupado como el propio galpón del origen.
        $this->command('POST', "/flocks/{$source->public_id}/redistributions", [
            'version' => 1, 'quantity' => 10, 'destination_poultry_house_id' => $occupiedHouse->id, 'destination_code' => 'OCUPADO',
        ])->assertConflict();
        $this->command('POST', "/flocks/{$source->public_id}/redistributions", [
            'version' => 1, 'quantity' => 10, 'destination_poultry_house_id' => $source->poultry_house_id, 'destination_code' => 'MISMO-GALPON',
        ])->assertConflict();

        // Verificación: no se crean lotes ni movimientos compensatorios.
        $this->assertDatabaseCount('flocks', 2);
        $this->assertDatabaseCount('flock_movements', 0);
    }

    // Flujo: traslada el lote completo entre UP conservando identidad e historial.
    public function test_total_transfer_moves_the_same_flock_without_deleting_it(): void
    {
        // Preparación: configura otro galpón en una unidad distinta.
        $this->signIn();
        $flock = $this->flock(30);
        $oldHouse = $flock->poultry_house_id;
        $house = PoultryHouse::factory()->create();

        // Request: traslada todas las aves sin crear un lote destinatario.
        $this->command('POST', "/flocks/{$flock->public_id}/redistributions", [
            'version' => 1, 'quantity' => 30, 'destination_poultry_house_id' => $house->id,
        ])->assertCreated()->assertJsonPath('data.flock.id', $flock->public_id)
            ->assertJsonPath('data.flock.poultry_house_id', $house->id)->assertJsonPath('data.movement.type', 'total')
            ->assertJsonPath('data.movement.source_poultry_house_id', $oldHouse);
        $this->assertDatabaseCount('flocks', 1);
        $this->assertSame($house->production_unit_id, $flock->fresh()->production_unit_id);
        $this->assertSame(0, $this->app->make(PoultryHouseOccupancyProvider::class)->occupancyFor($oldHouse));
        $this->assertSame(FlockStatus::Active, $flock->fresh()->status);
    }

    // Flujo: rechaza mezclas de raza y conserva los datos del receptor en una unión total.
    public function test_incompatible_breed_is_rejected_and_total_merge_finishes_source(): void
    {
        // Preparación: configura un destino inicialmente incompatible.
        $this->signIn();
        $source = $this->flock();
        $destination = $this->flock(20);
        $payload = ['version' => 1, 'quantity' => 10, 'destination_flock_id' => $destination->public_id, 'destination_version' => 1];

        // Request: no permite combinar razas diferentes.
        $this->command('POST', "/flocks/{$source->public_id}/redistributions", $payload)->assertConflict();
        $destination->forceFill(['breed_id' => $source->breed_id])->save();

        // Request: une todas las aves sin egreso ficticio y finaliza el origen.
        $this->command('POST', "/flocks/{$source->public_id}/redistributions", [...$payload, 'quantity' => 100])
            ->assertCreated()->assertJsonPath('data.movement.type', 'total_existing')
            ->assertJsonPath('data.flock.status', 'finished')
            ->assertJsonPath('data.destination_flock.current_quantity', 120);
        $this->assertDatabaseCount('flock_movements', 1);
        $this->assertDatabaseMissing('flock_movements', ['type' => 'departure']);
        $this->assertSame(120, (int) Flock::query()->sum('current_quantity'));
        $this->getJson("/api/v1/flocks/{$source->public_id}/history?type=total_existing")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'total_existing');
    }

    // Flujo: valida destino exclusivo y versión obligatoria del destinatario.
    public function test_recipient_selection_and_versions_are_validated(): void
    {
        // Preparación: crea los lotes involucrados.
        $this->signIn();
        $source = $this->flock();
        $destination = $this->flock(20, $source->breed);
        $url = "/flocks/{$source->public_id}/redistributions";
        $payload = ['version' => 1, 'quantity' => 10, 'destination_flock_id' => $destination->public_id];

        // Requests: rechaza ausencia de versión, destinos ambiguos y versión obsoleta.
        $this->command('POST', $url, $payload)->assertUnprocessable()->assertJsonValidationErrors('destination_version');
        $this->command('POST', $url, [...$payload, 'destination_version' => 1, 'destination_poultry_house_id' => $destination->poultry_house_id])->assertUnprocessable();
        $this->command('POST', $url, [...$payload, 'destination_version' => 2])->assertConflict();
        $this->command('POST', $url, [...$payload, 'destination_version' => 1, 'destination_flock_id' => $source->public_id])->assertConflict();
        $this->assertDatabaseCount('flock_movements', 0);
    }

    // Flujo: evita sobregiros, sobreocupación y operaciones en cuarentena.
    public function test_quantity_capacity_and_quarantine_are_enforced_atomically(): void
    {
        // Preparación: prepara un destino con diez plazas.
        $this->signIn();
        $flock = $this->flock(100);
        $house = PoultryHouse::factory()->create(['bird_capacity' => 10]);
        $payload = ['version' => 1, 'quantity' => 101, 'destination_poultry_house_id' => $house->id, 'destination_code' => 'SIN-CUPO'];
        $url = "/flocks/{$flock->public_id}/redistributions";

        // Requests: comprueba faltante de aves, capacidad y estado del origen.
        $this->command('POST', $url, $payload)->assertConflict();
        $this->command('POST', $url, [...$payload, 'quantity' => 20])->assertConflict();
        $flock->forceFill(['status' => FlockStatus::Quarantined])->save();
        $this->command('POST', $url, [...$payload, 'quantity' => 10])->assertConflict();
        $this->assertDatabaseCount('flocks', 1);
        $this->assertSame(100, $flock->fresh()->current_quantity);
    }

    // Flujo: permite evacuar un galpón en mantenimiento pero no recibir en él.
    public function test_source_can_be_evacuated_while_destination_must_be_operational(): void
    {
        // Preparación: coloca el origen en mantenimiento.
        $this->signIn();
        $flock = $this->flock(20);
        PoultryHouse::query()->findOrFail($flock->poultry_house_id)->forceFill(['status' => 'maintenance'])->save();
        $destination = PoultryHouse::factory()->create(['status' => 'maintenance']);

        // Requests: falla el destino cerrado y permite el traslado al habilitarlo.
        $payload = ['version' => 1, 'quantity' => 20, 'destination_poultry_house_id' => $destination->id];
        $this->command('POST', "/flocks/{$flock->public_id}/redistributions", $payload)->assertConflict();
        $destination->forceFill(['status' => 'operational'])->save();
        $this->command('POST', "/flocks/{$flock->public_id}/redistributions", $payload)->assertCreated();
    }

    // Flujo: revierte una redistribución parcial conservando el movimiento original.
    public function test_reversal_is_compensating_and_finishes_only_the_new_empty_flock(): void
    {
        // Preparación: registra una redistribución sin operaciones posteriores.
        $this->signIn();
        $flock = $this->flock();
        $house = PoultryHouse::factory()->create();
        $operation = $this->command('POST', "/flocks/{$flock->public_id}/redistributions", [
            'version' => 1, 'quantity' => 40, 'destination_poultry_house_id' => $house->id, 'destination_code' => 'A-REVERTIR',
        ])->assertCreated();
        $movement = FlockMovement::query()->firstOrFail();
        $original = $movement->getAttributes();

        // Request: repone las cantidades mediante un nuevo movimiento enlazado.
        $this->command('POST', '/redistributions/'.$operation->json('data.movement.id').'/reversals', [
            'version' => 2, 'destination_version' => 1, 'reason' => 'Se seleccionó un galpón equivocado',
        ])->assertOk()->assertJsonPath('data.flock.current_quantity', 100)
            ->assertJsonPath('data.destination_flock.status', 'finished')->assertJsonPath('data.destination_flock.current_quantity', 0);
        $this->assertEquals($original, $movement->fresh()->getAttributes());
        $this->assertDatabaseHas('flock_movements', ['type' => 'redistribution_reversal', 'reverses_movement_id' => $movement->id]);
        $this->assertDatabaseCount('flocks', 2);
    }

    // Flujo: impide deshacer una redistribución cuando cualquiera de los lotes ya cambió.
    public function test_reversal_rejects_subsequent_operations(): void
    {
        // Preparación: registra un traslado y después modifica el lote origen.
        $this->signIn();
        $flock = $this->flock();
        $house = PoultryHouse::factory()->create();
        $operation = $this->command('POST', "/flocks/{$flock->public_id}/redistributions", [
            'version' => 1, 'quantity' => 100, 'destination_poultry_house_id' => $house->id,
        ])->assertCreated();
        $this->command('PATCH', "/flocks/{$flock->public_id}", ['version' => 2, 'notes' => 'Inspeccionado'])->assertOk();

        // Request: rechaza la reversión sin borrar ni reescribir la historia.
        $this->command('POST', '/redistributions/'.$operation->json('data.movement.id').'/reversals', [
            'version' => 3, 'reason' => 'No corresponde revertir sobre cambios posteriores',
        ])->assertConflict();
        $this->assertDatabaseCount('flock_movements', 1);
        $this->assertSame($house->id, $flock->fresh()->poultry_house_id);
    }

    // Flujo: una recolección posterior del receptor también invalida la reversión.
    public function test_reversal_rejects_later_egg_collection_activity(): void
    {
        // Preparación: redistribuye aves y habilita los permisos de recolección.
        $this->signIn(['flocks.view', 'flocks.manage', 'flocks.redistribute', 'flocks.finalize', 'egg-collections.view', 'egg-collections.manage', 'egg-stock.view', 'egg-stock.move', 'egg-stock.adjust']);
        $source = $this->flock();
        $house = PoultryHouse::factory()->create();
        $operation = $this->command('POST', "/flocks/{$source->public_id}/redistributions", [
            'version' => 1, 'quantity' => 20, 'destination_poultry_house_id' => $house->id, 'destination_code' => 'RECEPTOR-EGG',
        ])->assertCreated();
        $destination = Flock::query()->where('code', 'RECEPTOR-EGG')->firstOrFail();

        // Mutación: registra una recolección sobre el lote receptor.
        $this->command('POST', "/flocks/{$destination->public_id}/collections", ['quantity' => 4])->assertCreated();

        // Request: rechaza la compensación porque la actividad posterior está journalizada.
        $this->command('POST', '/redistributions/'.$operation->json('data.movement.id').'/reversals', [
            'version' => 2, 'destination_version' => 1, 'reason' => 'La recolección ya fue registrada',
        ])->assertConflict();
        $this->assertDatabaseCount('flock_movements', 1);
    }

    // Flujo: un pesaje posterior del receptor también invalida la compensación.
    public function test_reversal_rejects_later_weighing_activity(): void
    {
        // Preparación: crea una redistribución parcial y habilita el módulo de pesajes.
        $this->signIn(['flocks.view', 'flocks.manage', 'flocks.redistribute', 'flocks.finalize', 'weighings.view', 'weighings.manage']);
        $source = $this->flock();
        $house = PoultryHouse::factory()->create();
        $operation = $this->command('POST', "/flocks/{$source->public_id}/redistributions", [
            'version' => 1, 'quantity' => 20, 'destination_poultry_house_id' => $house->id, 'destination_code' => 'RECEPTOR-PESO',
        ])->assertCreated();
        $destination = Flock::query()->where('code', 'RECEPTOR-PESO')->firstOrFail();

        // Mutación: registra un pesaje posterior sobre el receptor.
        $this->command('POST', '/pesajes', [
            'flock_id' => $destination->public_id,
            'mode' => 'individual',
            'unit' => 'g',
            'measurements' => [['weight' => '20.0']],
        ])->assertCreated();

        // Request: rechaza la compensación porque el pesaje quedó journalizado.
        $this->command('POST', '/redistributions/'.$operation->json('data.movement.id').'/reversals', [
            'version' => 2, 'destination_version' => 1, 'reason' => 'El pesaje ya fue registrado',
        ])->assertConflict();
        $this->assertDatabaseCount('flock_movements', 1);
    }

    // Flujo: deshace una unión total y reactiva el origen sólo con su galpón libre.
    public function test_total_existing_reversal_restores_source_and_receiver_snapshots(): void
    {
        // Preparación: une un lote origen a un receptor compatible en otro galpón.
        $this->signIn();
        $breed = Breed::factory()->create();
        $source = $this->flock(100, $breed);
        $destination = $this->flock(20, $breed);
        $operation = $this->command('POST', "/flocks/{$source->public_id}/redistributions", [
            'version' => 1, 'quantity' => 100, 'destination_flock_id' => $destination->public_id, 'destination_version' => 1,
        ])->assertCreated()->assertJsonPath('data.movement.type', 'total_existing');
        $movement = FlockMovement::query()->firstOrFail();

        // Request: compensa la unión y restaura ambos estados históricos.
        $this->command('POST', '/redistributions/'.$operation->json('data.movement.id').'/reversals', [
            'version' => 2, 'destination_version' => 2, 'reason' => 'Unión anulada',
        ])->assertOk()->assertJsonPath('data.flock.current_quantity', 100)
            ->assertJsonPath('data.flock.status', 'active')
            ->assertJsonPath('data.destination_flock.current_quantity', 20);
        $this->assertSame(FlockStatus::Active, $source->fresh()->status);
        $this->assertSame(20, $destination->fresh()->current_quantity);
        $this->assertDatabaseHas('flock_movements', ['type' => 'redistribution_reversal', 'reverses_movement_id' => $movement->id]);
    }

    // Flujo: impide devolver un traslado total a un galpón ocupado después.
    public function test_total_reversal_rejects_new_occupation_of_return_house(): void
    {
        // Preparación: traslada el lote completo y ocupa su galpón anterior.
        $this->signIn();
        $source = $this->flock();
        $oldHouse = $source->poultry_house_id;
        $destination = PoultryHouse::factory()->create();
        $operation = $this->command('POST', "/flocks/{$source->public_id}/redistributions", [
            'version' => 1, 'quantity' => 100, 'destination_poultry_house_id' => $destination->id,
        ])->assertCreated();
        $this->flock(10, $source->breed, PoultryHouse::query()->findOrFail($oldHouse));

        // Request: rechaza la reversión porque el galpón de retorno dejó de estar libre.
        $this->command('POST', '/redistributions/'.$operation->json('data.movement.id').'/reversals', [
            'version' => 2, 'reason' => 'El galpón de retorno fue ocupado',
        ])->assertConflict();
        $this->assertSame($destination->id, $source->fresh()->poultry_house_id);
        $this->assertDatabaseCount('flock_movements', 1);
    }

    // Flujo: una redistribución histórica sin verificación completa nunca se autoriza por ausencia de journal.
    public function test_unverified_legacy_redistribution_is_not_reversible(): void
    {
        // Preparación: crea una redistribución y la marca como historial incierto.
        $this->signIn();
        $source = $this->flock();
        $house = PoultryHouse::factory()->create();
        $operation = $this->command('POST', "/flocks/{$source->public_id}/redistributions", [
            'version' => 1, 'quantity' => 20, 'destination_poultry_house_id' => $house->id, 'destination_code' => 'LEGACY-UNCERTAIN',
        ])->assertCreated();
        $movement = FlockMovement::query()->firstOrFail();
        $movement->forceFill(['reversal_verified' => false])->save();

        // Request: rechaza la compensación sin tocar las fotografías ni las cantidades.
        $this->command('POST', '/redistributions/'.$operation->json('data.movement.id').'/reversals', [
            'version' => 2, 'destination_version' => 1, 'reason' => 'Historial incompleto',
        ])->assertConflict();
        $this->assertDatabaseCount('flock_movements', 1);
        $this->assertSame(80, $source->fresh()->current_quantity);
    }

    // Flujo: la auditoría y los cambios de ambos lotes forman una única transacción.
    public function test_audit_failure_rolls_back_both_flocks_and_movement(): void
    {
        // Preparación: instala una falla de auditoría después de actualizar cantidades.
        $this->signIn();
        $source = $this->flock();
        $destination = $this->flock(20, $source->breed);
        $this->mock(AuditRecorder::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Fallo controlado de redistribución'));

        // Request: comprueba reversión de las dos cantidades y de la operación.
        $this->command('POST', "/flocks/{$source->public_id}/redistributions", [
            'version' => 1, 'quantity' => 10, 'destination_flock_id' => $destination->public_id, 'destination_version' => 1,
        ])->assertStatus(500);
        $this->assertSame(100, $source->fresh()->current_quantity);
        $this->assertSame(20, $destination->fresh()->current_quantity);
        $this->assertDatabaseCount('flock_movements', 0);
        $this->assertDatabaseCount('flock_operations', 0);
    }

    // Flujo: un reintento de traslado no vuelve a mover aves ni generar identidades.
    public function test_transfer_replay_is_identical_even_with_stale_versions(): void
    {
        // Preparación: fija una clave de operación para ambos envíos.
        $this->signIn();
        $flock = $this->flock();
        $house = PoultryHouse::factory()->create();
        $payload = ['version' => 1, 'quantity' => 10, 'destination_poultry_house_id' => $house->id, 'destination_code' => 'REPLAY'];
        $key = (string) Str::uuid();

        // Requests: repite exactamente la misma redistribución.
        $first = $this->command('POST', "/flocks/{$flock->public_id}/redistributions", $payload, $key)->assertCreated();
        $second = $this->command('POST', "/flocks/{$flock->public_id}/redistributions", $payload, $key)->assertCreated();
        $this->assertSame($first->json(), $second->json());
        $this->assertDatabaseCount('flocks', 2);
        $this->assertDatabaseCount('flock_movements', 1);
        $this->assertDatabaseCount('flock_activities', 2);
    }
}
