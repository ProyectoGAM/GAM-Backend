<?php

namespace Tests\Feature\Lots;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\Lots\Breed;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\FarmStructure\Application\PublicApi\Contracts\PoultryHouseOccupancyProvider;
use App\Modules\Lots\Domain\Enums\FlockStatus;
use App\Modules\Lots\Domain\Events\FlockCreated;
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
            'codigo' => 'TEST-A', 'raza_id' => Breed::factory()->create()->id,
            'proveedor_id' => Supplier::factory()->create()->id, 'galpon_id' => PoultryHouse::factory()->create(['bird_capacity' => 100])->id,
            'cantidad_inicial' => 100, 'fecha_ingreso' => now(config('lots.timezone'))->subDays(7)->toDateString(),
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
        $response = $this->command('POST', '/lotes', $payload)->assertCreated()
            ->assertJsonPath('data.lote.cantidad_viva', 100)
            ->assertJsonPath('data.lote.semana_actual', 2);
        $this->assertTrue(Str::isUlid($response->json('data.lote.id')));
        $this->assertDatabaseHas('poultry_houses', ['id' => $payload['galpon_id'], 'bird_capacity' => 100]);
        $this->assertSame(100, $this->app->make(PoultryHouseOccupancyProvider::class)->occupancyFor($payload['galpon_id']));
        $this->assertDatabaseHas('activity_log', ['event' => 'flock_created', 'operation_id' => $response->json('data.id_operacion')]);
        Event::assertDispatched(FlockCreated::class);
    }

    // Flujo: permite origen propio sin inventar un proveedor.
    public function test_creation_supports_internal_origin(): void
    {
        // Preparación: sustituye el proveedor por una procedencia descriptiva.
        $this->signIn();
        $payload = $this->payload();
        unset($payload['proveedor_id']);
        $payload['origen'] = 'Cría propia';

        // Request: registra el origen independiente del catálogo de proveedores.
        $this->command('POST', '/lotes', $payload)->assertCreated()->assertJsonPath('data.lote.origen', 'Cría propia');
    }

    // Flujo: reintenta el alta tras cambiar el lote y verifica ausencia de duplicados.
    public function test_idempotency_precedes_version_and_capacity_validation(): void
    {
        // Preparación: usa una clave estable.
        $this->signIn();
        $payload = $this->payload();
        $key = (string) Str::uuid();
        $first = $this->command('POST', '/lotes', $payload, $key)->assertCreated();

        // Mutación: modifica el lote antes del reintento.
        $this->command('PATCH', '/lotes/'.$first->json('data.lote.id'), ['version' => 1, 'observaciones' => 'Revisado'])->assertOk();
        $replay = $this->command('POST', '/lotes', $payload, $key)->assertCreated();
        $this->assertSame($first->json(), $replay->json());
        $this->assertDatabaseCount('flocks', 1);
        $this->assertDatabaseCount('flock_movements', 1);

        // Request: la misma clave con otro contenido no puede crear otro ingreso.
        $this->command('POST', '/lotes', [...$payload, 'cantidad_inicial' => 99], $key)->assertConflict();
    }

    // Flujo: comprueba fronteras de acceso sin permisos implícitos.
    public function test_authentication_and_functional_permissions_are_required(): void
    {
        // Request: sin sesión no permite acceder.
        $this->getJson('/api/v1/lotes')->assertUnauthorized();
        $this->signIn([]);
        $this->getJson('/api/v1/lotes')->assertForbidden();
        $this->command('POST', '/lotes', [])->assertForbidden();
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidFields(): array
    {
        return [
            'cero' => ['cantidad_inicial', 0],
            'fracción' => ['cantidad_inicial', 1.5],
            'fuera de rango' => ['cantidad_inicial', 2147483648],
            'código vacío' => ['codigo', ''],
            'fecha inválida' => ['fecha_ingreso', '2026-02-30'],
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
        $this->command('POST', '/lotes', [...$payload, $field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('flocks', 0);
    }

    // Flujo: impide editar cantidades o aceptar campos internos.
    public function test_update_only_allows_descriptive_fields(): void
    {
        // Preparación: crea un lote activo.
        $this->signIn();
        $flock = $this->flock();

        // Request: intenta cambiar cantidades fuera de sus operaciones.
        $this->command('PATCH', "/lotes/{$flock->public_id}", ['version' => 1, 'cantidad_inicial' => 500, 'current_quantity' => 500])
            ->assertUnprocessable()->assertJsonValidationErrors(['cantidad_inicial', 'current_quantity']);
        $this->assertSame(100, $flock->fresh()->current_quantity);
    }

    // Flujo: bloquea altas en instalaciones no operativas o llenas.
    public function test_inactive_and_over_capacity_admissions_are_rejected(): void
    {
        // Preparación: crea un galpón con capacidad insuficiente.
        $this->signIn();
        $payload = $this->payload();
        $this->command('POST', '/lotes', [...$payload, 'cantidad_inicial' => 101])->assertConflict();

        // Mutación: retira el galpón de operación.
        PoultryHouse::query()->whereKey($payload['galpon_id'])->update(['status' => 'maintenance']);
        $this->command('POST', '/lotes', $payload)->assertConflict();
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
        $this->command('POST', '/lotes', $payload)->assertStatus(500);
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
        $this->command('POST', "/lotes/{$flock->public_id}/finalizacion", ['version' => 1, 'motivo' => 'Retiro al terminar el ciclo'])
            ->assertOk()->assertJsonPath('data.lote.estado', 'finished')->assertJsonPath('data.movimiento.cantidad', 20);
        $this->assertSame(0, $flock->fresh()->current_quantity);
        $this->assertSame(0, $this->app->make(PoultryHouseOccupancyProvider::class)->occupancyFor($flock->poultry_house_id));
        $this->assertDatabaseCount('mortality_records', 0);
        $this->assertDatabaseHas('flock_movements', ['type' => 'departure', 'quantity' => 20]);
        $this->getJson("/api/v1/lotes/{$flock->public_id}")->assertOk()->assertJsonPath('data.cantidad_inicial', 20);

        // Request: una nueva finalización o reapertura no está permitida.
        $this->command('POST', "/lotes/{$flock->public_id}/finalizacion", ['version' => 2, 'motivo' => 'Duplicado'])->assertConflict();
        $this->command('PATCH', "/lotes/{$flock->public_id}/estado", ['version' => 2, 'estado' => 'active', 'motivo' => 'Reabrir'])->assertConflict();
    }

    // Flujo: la cuarentena conserva ocupación y las versiones evitan sobrescrituras.
    public function test_quarantine_and_stale_versions_are_enforced(): void
    {
        // Preparación: crea un lote operativo.
        $this->signIn();
        $flock = $this->flock();

        // Request: cambia el estado e intenta una escritura obsoleta.
        $this->command('PATCH', "/lotes/{$flock->public_id}/estado", ['version' => 1, 'estado' => 'quarantined', 'motivo' => 'Observación'])->assertOk();
        $this->assertSame(100, $this->app->make(PoultryHouseOccupancyProvider::class)->occupancyFor($flock->poultry_house_id));
        $this->command('PATCH', "/lotes/{$flock->public_id}", ['version' => 1, 'observaciones' => 'Desactualizado'])->assertConflict();
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
        $this->getJson('/api/v1/lotes?por_pagina=1')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson("/api/v1/galpones/{$a->poultry_house_id}/lotes")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->public_id);
        $this->getJson('/api/v1/lotes?buscar='.$b->code)->assertOk()->assertJsonPath('data.0.id', $b->public_id);
        $this->getJson('/api/v1/lotes?por_pagina=101')->assertUnprocessable();
    }
}
