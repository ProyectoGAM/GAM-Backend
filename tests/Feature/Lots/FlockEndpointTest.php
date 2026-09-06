<?php

namespace Tests\Feature\Lots;

use App\Enums\Lots\FlockStatus;
use App\Events\Lots\FlockCreated;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Interfaces\FarmStructure\PoultryHouseOccupancyProvider;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\Lots\Breed;
use App\Models\SuppliersAndCatalogs\Supplier;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class FlockEndpointTest extends LotsTestCase
{
    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'code' => 'TEST-A', 'breed_id' => Breed::factory()->create()->id,
            'supplier_id' => Supplier::factory()->create()->id, 'poultry_house_id' => PoultryHouse::factory()->create(['bird_capacity' => 100])->id,
            'initial_quantity' => 100, 'entry_date' => now(config('lots.timezone'))->subDays(7)->toDateString(),
        ];
    }

    // Flujo: registra un lote, cuenta ocupación y comprueba auditoría y semana.
    public function test_creation_uses_public_contract_and_physical_capacity_is_unchanged(): void
    {
        // Preparación: autentica al gestor y configura referencias activas.
        $this->signIn();
        $payload = $this->payload();
        Event::fake([FlockCreated::class]);

        // Request: registra una admisión de cien aves.
        $response = $this->command('POST', '/flocks', $payload)->assertCreated()
            ->assertJsonPath('data.flock.current_quantity', 100)
            ->assertJsonPath('data.flock.current_week', 2);
        $this->assertTrue(Str::isUlid($response->json('data.flock.id')));
        $this->assertDatabaseHas('poultry_houses', ['id' => $payload['poultry_house_id'], 'bird_capacity' => 100]);
        $this->assertSame(100, $this->app->make(PoultryHouseOccupancyProvider::class)->occupancyFor($payload['poultry_house_id']));
        $this->assertDatabaseHas('activity_log', ['event' => 'flock_created', 'operation_id' => $response->json('data.operation_id')]);
        Event::assertDispatched(FlockCreated::class);
    }

    // Flujo: permite origen propio sin inventar un proveedor.
    public function test_creation_supports_internal_origin(): void
    {
        // Preparación: sustituye el proveedor por una procedencia descriptiva.
        $this->signIn();
        $payload = $this->payload();
        unset($payload['supplier_id']);
        $payload['origin'] = 'Cría propia';

        // Request: registra el origen independiente del catálogo de proveedores.
        $this->command('POST', '/flocks', $payload)->assertCreated()->assertJsonPath('data.flock.origin', 'Cría propia');
    }

    // Flujo: reintenta el alta tras cambiar el lote y verifica ausencia de duplicados.
    public function test_idempotency_precedes_version_and_capacity_validation(): void
    {
        // Preparación: usa una clave estable.
        $this->signIn();
        $payload = $this->payload();
        $key = (string) Str::uuid();
        $first = $this->command('POST', '/flocks', $payload, $key)->assertCreated();

        // Mutación: modifica el lote antes del reintento.
        $this->command('PATCH', '/flocks/'.$first->json('data.flock.id'), ['version' => 1, 'notes' => 'Revisado'])->assertOk();
        $replay = $this->command('POST', '/flocks', $payload, $key)->assertCreated();
        $this->assertSame($first->json(), $replay->json());
        $this->assertDatabaseCount('flocks', 1);
        $this->assertDatabaseCount('flock_movements', 1);

        // Request: la misma clave con otro contenido no puede crear otro ingreso.
        $this->command('POST', '/flocks', [...$payload, 'initial_quantity' => 99], $key)->assertConflict();
    }

    // Flujo: comprueba fronteras de acceso sin permisos implícitos.
    public function test_authentication_and_functional_permissions_are_required(): void
    {
        // Request: sin sesión no permite acceder.
        $this->getJson('/api/v1/flocks')->assertUnauthorized();
        $this->signIn([]);
        $this->getJson('/api/v1/flocks')->assertForbidden();
        $this->command('POST', '/flocks', [])->assertForbidden();
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidFields(): array
    {
        return [
            'cero' => ['initial_quantity', 0],
            'fracción' => ['initial_quantity', 1.5],
            'fuera de rango' => ['initial_quantity', 2147483648],
            'código vacío' => ['code', ''],
            'fecha inválida' => ['entry_date', '2026-02-30'],
        ];
    }

    // Flujo: rechaza entradas inválidas sin persistencia parcial.
    #[DataProvider('invalidFields')]
    public function test_invalid_payload_returns_422(string $field, mixed $value): void
    {
        // Preparación: cambia sólo el campo cuyo contrato se evalúa.
        $this->signIn();
        $payload = $this->payload();

        // Request: verifica el error del campo y ausencia de lotes.
        $this->command('POST', '/flocks', [...$payload, $field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('flocks', 0);
    }

    // Flujo: impide editar cantidades o aceptar campos internos.
    public function test_update_only_allows_descriptive_fields(): void
    {
        // Preparación: crea un lote activo.
        $this->signIn();
        $flock = $this->flock();

        // Request: intenta cambiar cantidades fuera de sus operaciones.
        $this->command('PATCH', "/flocks/{$flock->public_id}", ['version' => 1, 'initial_quantity' => 500, 'current_quantity' => 500])
            ->assertUnprocessable()->assertJsonValidationErrors(['initial_quantity', 'current_quantity']);
        $this->assertSame(100, $flock->fresh()->current_quantity);
    }

    // Flujo: bloquea altas en instalaciones no operativas o llenas.
    public function test_inactive_and_over_capacity_admissions_are_rejected(): void
    {
        // Preparación: crea un galpón con capacidad insuficiente.
        $this->signIn();
        $payload = $this->payload();
        $this->command('POST', '/flocks', [...$payload, 'initial_quantity' => 101])->assertConflict();

        // Mutación: retira el galpón de operación.
        PoultryHouse::query()->whereKey($payload['poultry_house_id'])->update(['status' => 'maintenance']);
        $this->command('POST', '/flocks', $payload)->assertConflict();
        $this->assertDatabaseCount('flocks', 0);
    }

    // Flujo: revierte alta y movimiento cuando falla la auditoría síncrona.
    public function test_audit_failure_rolls_back_admission(): void
    {
        // Preparación: instala una falla en el contrato de auditoría.
        $this->signIn();
        $payload = $this->payload();
        Event::fake([FlockCreated::class]);
        $this->mock(AuditRecorder::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Fallo controlado de auditoría'));

        // Request: comprueba rollback completo.
        $this->command('POST', '/flocks', $payload)->assertStatus(500);
        $this->assertDatabaseCount('flocks', 0);
        $this->assertDatabaseCount('flock_movements', 0);
        $this->assertDatabaseCount('flock_operations', 0);
        Event::assertNotDispatched(FlockCreated::class);
    }

    // Flujo: finaliza con egreso, conserva historia y no crea mortalidad.
    public function test_finalization_releases_occupancy_and_records_departure(): void
    {
        // Preparación: crea un lote que todavía contiene aves.
        $this->signIn();
        $flock = $this->flock(20);

        // Request: finaliza mediante egreso explícito.
        $this->command('POST', "/flocks/{$flock->public_id}/finalization", ['version' => 1, 'reason' => 'Retiro al terminar el ciclo'])
            ->assertOk()->assertJsonPath('data.flock.status', 'finished')->assertJsonPath('data.movement.quantity', 20);
        $this->assertSame(0, $flock->fresh()->current_quantity);
        $this->assertSame(0, $this->app->make(PoultryHouseOccupancyProvider::class)->occupancyFor($flock->poultry_house_id));
        $this->assertDatabaseCount('mortality_records', 0);
        $this->assertDatabaseHas('flock_movements', ['type' => 'departure', 'quantity' => 20]);
        $this->getJson("/api/v1/flocks/{$flock->public_id}")->assertOk()->assertJsonPath('data.initial_quantity', 20);

        // Request: una nueva finalización o reapertura no está permitida.
        $this->command('POST', "/flocks/{$flock->public_id}/finalization", ['version' => 2, 'reason' => 'Duplicado'])->assertConflict();
        $this->command('PATCH', "/flocks/{$flock->public_id}/status", ['version' => 2, 'status' => 'active', 'reason' => 'Reabrir'])->assertConflict();
    }

    // Flujo: la cuarentena conserva ocupación y las versiones evitan sobrescrituras.
    public function test_quarantine_and_stale_versions_are_enforced(): void
    {
        // Preparación: crea un lote operativo.
        $this->signIn();
        $flock = $this->flock();

        // Request: cambia el estado e intenta una escritura obsoleta.
        $this->command('PATCH', "/flocks/{$flock->public_id}/status", ['version' => 1, 'status' => 'quarantined', 'reason' => 'Observación'])->assertOk();
        $this->assertSame(100, $this->app->make(PoultryHouseOccupancyProvider::class)->occupancyFor($flock->poultry_house_id));
        $this->command('PATCH', "/flocks/{$flock->public_id}", ['version' => 1, 'notes' => 'Desactualizado'])->assertConflict();
        $this->assertSame(FlockStatus::Quarantined, $flock->fresh()->status);
    }

    // Flujo: lista varias UP con filtros y paginación pública estable.
    public function test_listing_filters_and_pagination_do_not_scope_by_user(): void
    {
        // Preparación: crea lotes en diferentes galpones.
        $this->signIn(['flocks.view']);
        $a = $this->flock();
        $b = $this->flock();

        // Consulta: los permisos funcionales permiten ambas unidades.
        $this->getJson('/api/v1/flocks?per_page=1')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson("/api/v1/poultry-houses/{$a->poultry_house_id}/flocks")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->public_id);
        $this->getJson('/api/v1/flocks?search='.$b->code)->assertOk()->assertJsonPath('data.0.id', $b->public_id);
        $this->getJson('/api/v1/flocks?per_page=101')->assertUnprocessable();
    }
}
