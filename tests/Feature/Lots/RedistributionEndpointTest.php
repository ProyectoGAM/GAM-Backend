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

    // Flujo: redistribuye dentro de un galpón lleno sin contabilizar dos veces las aves.
    public function test_same_house_redistribution_uses_net_occupancy(): void
    {
        // Preparación: ocupa exactamente toda la capacidad física.
        $this->signIn();
        $house = PoultryHouse::factory()->create(['bird_capacity' => 100]);
        $breed = Breed::factory()->create();
        $source = $this->flock(80, $breed, $house);
        $destination = $this->flock(20, $breed, $house);

        // Request: cambia la agrupación sin añadir ocupación al galpón.
        $this->command('POST', "/flocks/{$source->public_id}/redistributions", [
            'version' => 1, 'quantity' => 10, 'destination_flock_id' => $destination->public_id, 'destination_version' => 1,
        ])->assertCreated();
        $this->assertSame(100, $this->app->make(PoultryHouseOccupancyProvider::class)->occupancyFor($house->id));
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

    // Flujo: rechaza mezclas de raza y fusiones totales ambiguas.
    public function test_incompatible_breed_and_total_merge_are_rejected(): void
    {
        // Preparación: configura un destino inicialmente incompatible.
        $this->signIn();
        $source = $this->flock();
        $destination = $this->flock();
        $payload = ['version' => 1, 'quantity' => 10, 'destination_flock_id' => $destination->public_id, 'destination_version' => 1];

        // Request: no permite combinar razas diferentes.
        $this->command('POST', "/flocks/{$source->public_id}/redistributions", $payload)->assertConflict();
        $destination->forceFill(['breed_id' => $source->breed_id])->save();

        // Request: una redistribución total requiere galpón, no fusión con otro lote.
        $this->command('POST', "/flocks/{$source->public_id}/redistributions", [...$payload, 'quantity' => 100])->assertConflict();
        $this->assertDatabaseCount('flock_movements', 0);
        $this->assertSame(200, (int) Flock::query()->sum('current_quantity'));
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
    }
}
