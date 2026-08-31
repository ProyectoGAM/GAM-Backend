<?php

namespace Tests\Feature\Lots;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockMovement;
use App\Models\Lots\MortalityCategory;
use App\Modules\Lots\Domain\Enums\FlockStatus;
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
        $created = $this->command('POST', "/lotes/{$flock->public_id}/mortalidades", [
            'version' => 1, 'cantidad' => 5, 'categoria_mortalidad_id' => $category->id,
        ])->assertCreated()->assertJsonPath('data.lote.cantidad_viva', 95)->assertJsonPath('data.lote.cantidad_inicial', 100);
        $id = $created->json('data.mortalidad.id');
        $original = FlockMovement::query()->firstOrFail()->getAttributes();

        // Request: corrige a tres bajas mediante un movimiento compensatorio de dos aves.
        $this->command('PATCH', "/mortalidades/{$id}", [
            'version' => 1, 'version_lote' => 2, 'cantidad' => 3, 'motivo' => 'Recuento verificado',
        ])->assertOk()->assertJsonPath('data.lote.cantidad_viva', 97)->assertJsonPath('data.mortalidad.cantidad', 3)
            ->assertJsonPath('data.movimiento.cantidad', 2);

        // Request: cancela la anotación y restaura el total sin borrar el registro.
        $this->command('POST', "/mortalidades/{$id}/cancelacion", [
            'version' => 2, 'version_lote' => 3, 'motivo' => 'Registro duplicado en la libreta',
        ])->assertOk()->assertJsonPath('data.lote.cantidad_viva', 100)->assertJsonPath('data.mortalidad.estado', 'cancelled');
        $this->assertDatabaseCount('mortality_records', 1);
        $this->assertDatabaseCount('flock_movements', 3);
        $this->assertEquals($original, FlockMovement::query()->firstOrFail()->getAttributes());
        $this->assertDatabaseHas('activity_log', ['event' => 'mortality_recorded']);
        $this->assertDatabaseHas('activity_log', ['event' => 'mortality_corrected']);
        $this->assertDatabaseHas('activity_log', ['event' => 'mortality_cancelled']);
    }

    // Flujo: las aves en cuarentena pueden registrar mortalidad sin cambiar de estado.
    public function test_quarantine_and_loss_of_all_birds_are_supported(): void
    {
        // Preparación: crea un lote pequeño en cuarentena.
        $this->signIn();
        $flock = Flock::factory()->quarantined()->create(['initial_quantity' => 3, 'current_quantity' => 3]);
        $category = MortalityCategory::factory()->create();

        // Request: registra todas las bajas sin una finalización implícita.
        $this->command('POST', "/lotes/{$flock->public_id}/mortalidades", [
            'version' => 1, 'cantidad' => 3, 'categoria_mortalidad_id' => $category->id,
        ])->assertCreated()->assertJsonPath('data.lote.cantidad_viva', 0)->assertJsonPath('data.lote.estado', 'quarantined');
        $this->assertSame(FlockStatus::Quarantined, $flock->fresh()->status);
    }

    // Flujo: impide superar la cantidad viva y usar categorías inactivas.
    public function test_negative_balance_and_inactive_category_are_rejected(): void
    {
        // Preparación: configura un lote de diez aves.
        $this->signIn();
        $flock = $this->flock(10);
        $category = MortalityCategory::factory()->create();
        $payload = ['version' => 1, 'cantidad' => 11, 'categoria_mortalidad_id' => $category->id];

        // Requests: comprueba cantidades y vigencia del catálogo sin efectos parciales.
        $this->command('POST', "/lotes/{$flock->public_id}/mortalidades", $payload)->assertConflict();
        $category->forceFill(['status' => 'inactive'])->save();
        $this->command('POST', "/lotes/{$flock->public_id}/mortalidades", [...$payload, 'cantidad' => 1])->assertConflict();
        $this->assertDatabaseCount('mortality_records', 0);
        $this->assertSame(10, $flock->fresh()->current_quantity);
    }

    // Flujo: una cancelación no puede sobreocupar el galpón si otra admisión usó las plazas.
    public function test_cancellation_rechecks_capacity_and_keeps_original_record_on_conflict(): void
    {
        // Preparación: llena un galpón y registra dos bajas.
        $this->signIn();
        $house = PoultryHouse::factory()->create(['bird_capacity' => 10]);
        $flock = $this->flock(10, house: $house);
        $category = MortalityCategory::factory()->create();
        $created = $this->command('POST', "/lotes/{$flock->public_id}/mortalidades", [
            'version' => 1, 'cantidad' => 2, 'categoria_mortalidad_id' => $category->id,
        ])->assertCreated();
        $this->flock(2, house: $house);

        // Request: no restaura aves cuando la capacidad ya está ocupada.
        $this->command('POST', '/mortalidades/'.$created->json('data.mortalidad.id').'/cancelacion', [
            'version' => 1, 'version_lote' => 2, 'motivo' => 'Corrección sin plazas disponibles',
        ])->assertConflict();
        $this->assertSame(8, $flock->fresh()->current_quantity);
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
        $created = $this->command('POST', "/lotes/{$flock->public_id}/mortalidades", [
            'version' => 1, 'cantidad' => 2, 'categoria_mortalidad_id' => $category->id,
        ])->assertCreated();
        $url = '/mortalidades/'.$created->json('data.mortalidad.id');

        // Requests: rechaza cada versión desactualizada por separado.
        $this->command('PATCH', $url, ['version' => 1, 'version_lote' => 1, 'cantidad' => 1, 'motivo' => 'Versión anterior'])->assertConflict();
        $this->command('PATCH', $url, ['version' => 2, 'version_lote' => 2, 'cantidad' => 1, 'motivo' => 'Versión incorrecta'])->assertConflict();
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
        $created = $this->command('POST', "/lotes/{$flock->public_id}/mortalidades", [
            'version' => 1, 'cantidad' => 1, 'categoria_mortalidad_id' => $category->id, 'ocurrido_en' => '2026-08-25T01:00:00+00:00',
        ])->assertCreated();
        $newHouse = PoultryHouse::factory()->create();
        $this->command('POST', "/lotes/{$flock->public_id}/redistribuciones", [
            'version' => 2, 'cantidad' => 9, 'galpon_destino_id' => $newHouse->id,
        ])->assertCreated();

        // Consultas: a las 01 UTC todavía es el día anterior en Montevideo.
        $this->getJson("/api/v1/mortalidades?lote_id={$flock->public_id}&galpon_id={$oldHouse}&fecha_desde=2026-08-24&fecha_hasta=2026-08-24")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $created->json('data.mortalidad.id'));
        $this->getJson("/api/v1/mortalidades?galpon_id={$newHouse->id}")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/mortalidades?fecha_desde=2026-08-25&fecha_hasta=2026-08-24')->assertUnprocessable();
    }

    // Flujo: no acepta hechos futuros ni anteriores a la presencia del lote.
    public function test_occurrence_must_respect_flock_timeline(): void
    {
        // Preparación: crea un lote vigente con fecha inicial conocida.
        $this->signIn();
        $flock = $this->flock();
        $category = MortalityCategory::factory()->create();
        $payload = ['version' => 1, 'cantidad' => 1, 'categoria_mortalidad_id' => $category->id];

        // Requests: rechaza ambos extremos incompatibles de la línea temporal.
        $this->command('POST', "/lotes/{$flock->public_id}/mortalidades", [...$payload, 'ocurrido_en' => now()->addDay()->toIso8601String()])->assertConflict();
        $this->command('POST', "/lotes/{$flock->public_id}/mortalidades", [...$payload, 'ocurrido_en' => $flock->established_at->subDay()->toIso8601String()])->assertConflict();
        $this->assertDatabaseCount('mortality_records', 0);
    }

    // Flujo: el reintento offline no registra nuevamente una baja ya aplicada.
    public function test_replay_does_not_double_mortality(): void
    {
        // Preparación: conserva la clave del comando original.
        $this->signIn();
        $flock = $this->flock(10);
        $category = MortalityCategory::factory()->create();
        $payload = ['version' => 1, 'cantidad' => 2, 'categoria_mortalidad_id' => $category->id];
        $key = (string) Str::uuid();

        // Requests: ambos envíos obtienen la misma respuesta persistida.
        $first = $this->command('POST', "/lotes/{$flock->public_id}/mortalidades", $payload, $key)->assertCreated();
        $second = $this->command('POST', "/lotes/{$flock->public_id}/mortalidades", $payload, $key)->assertCreated();
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
        $created = $this->command('POST', "/lotes/{$flock->public_id}/mortalidades", [
            'version' => 1, 'cantidad' => 1, 'categoria_mortalidad_id' => $category->id,
        ])->assertCreated();
        $url = '/mortalidades/'.$created->json('data.mortalidad.id').'/cancelacion';
        $this->command('POST', $url, ['version' => 1, 'version_lote' => 2, 'motivo' => 'Error'])->assertOk();

        // Requests: no duplica la compensación ni permite registrar en un lote finalizado.
        $this->command('POST', $url, ['version' => 2, 'version_lote' => 3, 'motivo' => 'Otra cancelación'])->assertConflict();
        $this->command('POST', "/lotes/{$flock->public_id}/finalizacion", ['version' => 3, 'motivo' => 'Fin de ciclo'])->assertOk();
        $this->command('POST', "/lotes/{$flock->public_id}/mortalidades", [
            'version' => 4, 'cantidad' => 1, 'categoria_mortalidad_id' => $category->id,
        ])->assertConflict();
        $this->assertDatabaseCount('mortality_records', 1);
    }
}
