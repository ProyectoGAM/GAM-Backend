<?php

namespace Tests\Feature\Lots;

use App\Enums\Lots\FlockStatus;
use App\Events\Lots\FlockCreated;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Interfaces\FarmStructure\PoultryHouseOccupancyProvider;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\Lots\Breed;
use App\Models\Lots\Flock;
use App\Models\ManagementPlans\PlanTemplate;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class FlockEndpointTest extends LotsTestCase
{
    /** @return array<string, mixed> */
    private function payload(): array
    {
        $plan = $this->createPublishedPlanSelection(User::query()->findOrFail(auth()->id()));

        return [
            'code' => 'TEST-A', 'breed_id' => Breed::factory()->create()->id,
            'supplier_id' => Supplier::factory()->create()->id, 'poultry_house_id' => PoultryHouse::factory()->create(['bird_capacity' => 100])->id,
            'initial_quantity' => 100, 'entry_date' => now(config('lots.timezone'))->subDays(7)->toDateString(),
            ...$plan,
        ];
    }

    // Flujo: suma current_quantity de lotes activos y en cuarentena y devuelve cero para galpones sin ocupación.
    public function test_batch_occupancy_uses_current_quantity_for_active_and_quarantined_flocks(): void
    {
        // Preparación: crea un galpón por estado para respetar la unicidad de lote abierto por galpón.
        $activeHouse = PoultryHouse::factory()->create();
        $quarantinedHouse = PoultryHouse::factory()->create();
        $finishedHouse = PoultryHouse::factory()->create();
        Flock::factory()->create([
            'poultry_house_id' => $activeHouse->getKey(),
            'initial_quantity' => 150,
            'current_quantity' => 100,
        ]);
        Flock::factory()->quarantined()->create([
            'poultry_house_id' => $quarantinedHouse->getKey(),
            'initial_quantity' => 50,
            'current_quantity' => 40,
        ]);
        Flock::factory()->finished()->create([
            'poultry_house_id' => $finishedHouse->getKey(),
            'initial_quantity' => 75,
        ]);

        // Consulta: obtiene la ocupación de los tres galpones mediante la consulta agrupada.
        $occupancies = $this->app->make(PoultryHouseOccupancyProvider::class)->occupanciesFor([
            (int) $activeHouse->getKey(),
            (int) $quarantinedHouse->getKey(),
            (int) $finishedHouse->getKey(),
        ]);

        // Verificación: suma current_quantity de estados abiertos y devuelve cero para el lote finalizado.
        $this->assertSame([
            (int) $activeHouse->getKey() => 100,
            (int) $quarantinedHouse->getKey() => 40,
            (int) $finishedHouse->getKey() => 0,
        ], $occupancies);
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
        $this->assertDatabaseHas('activity_log', ['event' => 'flock_plan_assigned', 'operation_id' => $response->json('data.operation_id')]);
        $flockId = Flock::query()->where('public_id', $response->json('data.flock.id'))->value('id');
        $plan = DB::table('flock_plans')->where('flock_id', $flockId)->first();
        $this->assertNotNull($plan);
        $revisionId = DB::table('flock_plan_revisions')->where('flock_plan_id', $plan->id)->value('id');
        $this->assertNotNull($revisionId);
        $this->assertDatabaseHas('flock_plan_activities', [
            'flock_plan_revision_id' => $revisionId,
            'title' => 'Pesaje de prueba',
        ]);
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

        // Mutación: modifica el lote y retira la plantilla antes del reintento.
        $this->command('PATCH', '/flocks/'.$first->json('data.flock.id'), ['version' => 1, 'notes' => 'Revisado'])->assertOk();
        PlanTemplate::query()->where('public_id', $payload['plan_template_id'])->update(['status' => 'retired']);
        $replay = $this->command('POST', '/flocks', $payload, $key)->assertCreated();
        $this->assertSame($first->json(), $replay->json());
        $this->assertDatabaseCount('flocks', 1);
        $this->assertDatabaseCount('flock_movements', 1);
        $this->assertDatabaseCount('flock_plans', 1);
        $this->assertDatabaseCount('flock_plan_revisions', 1);
        $this->assertDatabaseCount('flock_plan_activities', 1);
        $this->assertSame(1, DB::table('activity_log')->where('event', 'flock_plan_assigned')->count());
        $this->assertSame(1, DB::table('activity_log')->where('event', 'flock_created')->count());

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

    // Flujo: exige una versión publicada explícita antes de admitir aves.
    public function test_creation_requires_plan_template_and_version(): void
    {
        // Preparación: crea un alta válida y elimina la referencia al plan.
        $this->signIn();
        $payload = $this->payload();
        unset($payload['plan_template_id'], $payload['plan_template_version']);

        // Request: rechaza la admisión sin plantilla seleccionada.
        $this->command('POST', '/flocks', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['plan_template_id', 'plan_template_version']);
        $this->assertDatabaseCount('flocks', 0);
        $this->assertDatabaseCount('flock_operations', 0);
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

    // Flujo: impide un segundo lote abierto aunque el galpón conserve capacidad física.
    public function test_second_open_flock_in_same_house_returns_409(): void
    {
        // Preparación: admite el primer lote en un galpón con plazas sobrantes.
        $this->signIn();
        $payload = $this->payload();
        $this->command('POST', '/flocks', $payload)->assertCreated();

        // Request: intenta admitir otro lote en el mismo galpón.
        $this->command('POST', '/flocks', [...$payload, 'code' => 'TEST-B', 'initial_quantity' => 1])->assertConflict();

        // Verificación: conserva una sola ocupación abierta y ningún efecto parcial.
        $this->assertDatabaseCount('flocks', 1);
        $this->assertDatabaseCount('flock_movements', 1);
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
        $this->assertDatabaseCount('flock_plans', 0);
        $this->assertDatabaseCount('flock_plan_revisions', 0);
        $this->assertDatabaseCount('flock_plan_activities', 0);
        $this->assertDatabaseCount('flock_movements', 0);
        $this->assertDatabaseCount('flock_operations', 0);
        $this->assertDatabaseCount('activity_log', 0);
        Event::assertNotDispatched(FlockCreated::class);
    }

    // Flujo: revierte el alta completa cuando la versión dejó de ser la publicada.
    public function test_returns_409_and_rolls_back_when_selected_plan_version_is_unpublished(): void
    {
        // Preparación: solicita una versión distinta de la única versión publicada.
        $this->signIn();
        $payload = $this->payload();
        $payload['plan_template_version']++;

        // Request: comprueba el conflicto de versión y la ausencia de efectos parciales.
        $this->command('POST', '/flocks', $payload)->assertConflict();
        $this->assertDatabaseCount('flocks', 0);
        $this->assertDatabaseCount('flock_plans', 0);
        $this->assertDatabaseCount('flock_plan_revisions', 0);
        $this->assertDatabaseCount('flock_plan_activities', 0);
        $this->assertDatabaseCount('flock_movements', 0);
        $this->assertDatabaseCount('flock_operations', 0);
        $this->assertDatabaseCount('activity_log', 0);
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

    // Flujo: libera el galpón al finalizar un lote y permite una nueva admisión.
    public function test_new_admission_is_allowed_after_previous_flock_finishes(): void
    {
        // Preparación: admite un lote en un galpón con capacidad exacta.
        $this->signIn();
        $payload = $this->payload();
        $created = $this->command('POST', '/flocks', $payload)->assertCreated();

        // Mutación: finaliza el lote y deja el galpón disponible para historial futuro.
        $this->command('POST', '/flocks/'.$created->json('data.flock.id').'/finalization', [
            'version' => 1, 'reason' => 'Fin del ciclo',
        ])->assertOk();

        // Request: registra otro lote en el mismo galpón después de la liberación.
        $this->command('POST', '/flocks', [...$payload, 'code' => 'TEST-DESPUES', 'initial_quantity' => 50])->assertCreated();
        $this->assertDatabaseCount('flocks', 2);
        $this->assertSame(1, Flock::query()->where('poultry_house_id', $payload['poultry_house_id'])->whereIn('status', [FlockStatus::Active, FlockStatus::Quarantined])->count());
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
