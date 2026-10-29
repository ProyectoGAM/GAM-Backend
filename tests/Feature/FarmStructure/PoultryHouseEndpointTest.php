<?php

namespace Tests\Feature\FarmStructure;

use App\Interfaces\FarmStructure\PoultryHouseOccupancyProvider;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Lots\Flock;
use App\Models\User;
use App\Queries\FarmStructure\GetOperationalPoultryHouseQuery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class PoultryHouseEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: crea un galpón y verifica capacidad, estado inicial y auditoría.
    public function test_valid_payload_creates_poultry_house_without_mutating_capacity(): void
    {
        // Preparación: crea la unidad productiva y autentica al gestor.
        $unidadProductiva = ProductionUnit::factory()->create();
        $actor = $this->userWithPermissions(['poultry-houses.manage']);
        Sanctum::actingAs($actor, ['*']);

        // Acción: registra el galpón con su capacidad física.
        $response = $this->postJson(
            "/api/v1/production-units/{$unidadProductiva->getKey()}/poultry-houses",
            ['name' => 'House A', 'bird_capacity' => 12000],
        )->assertCreated()
            ->assertJsonPath('data.bird_capacity', 12000)
            ->assertJsonPath('data.status', 'operational');

        $poultryHouseId = (int) $response->json('data.id');

        // Verificación: confirma capacidad persistida y auditoría del alta.
        $this->assertDatabaseHas('poultry_houses', [
            'id' => $poultryHouseId,
            'production_unit_id' => $unidadProductiva->getKey(),
            'bird_capacity' => 12000,
        ]);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'poultry_house_created',
            'subject_id' => $poultryHouseId,
            'up_id' => $unidadProductiva->getKey(),
        ]);
    }

    // Flujo: crea una planta de ración y expone sus campos avícolas como nulos.
    public function test_valid_feed_payload_creates_ration_plant_without_bird_capacity(): void
    {
        // Preparación: crea la unidad productiva y autentica al gestor.
        $productionUnit = ProductionUnit::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['poultry-houses.manage']), ['*']);

        // Request: registra la planta sin enviar capacidad de aves.
        $response = $this->postJson(
            "/api/v1/production-units/{$productionUnit->getKey()}/poultry-houses",
            ['name' => 'Planta de ración A', 'type' => 'feed'],
        )->assertCreated()
            ->assertJsonPath('data.type', 'feed')
            ->assertJsonPath('data.bird_capacity', null)
            ->assertJsonPath('data.current_occupancy', null)
            ->assertJsonPath('data.capacity_exceeded', false)
            ->assertJsonPath('data.excess_quantity', null)
            ->assertJsonPath('data.capacity_alert', null);

        // Verificación: confirma el tipo feed y la ausencia de capacidad física.
        $this->assertDatabaseHas('poultry_houses', [
            'id' => $response->json('data.id'),
            'production_unit_id' => $productionUnit->getKey(),
            'type' => 'feed',
            'bird_capacity' => null,
        ]);
    }

    // Flujo: rechaza capacidad de aves al crear una planta de ración.
    public function test_feed_payload_rejects_bird_capacity(): void
    {
        // Preparación: crea la unidad productiva y autentica al gestor.
        $productionUnit = ProductionUnit::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['poultry-houses.manage']), ['*']);

        // Request: intenta enviar una capacidad incompatible con el tipo feed.
        $this->postJson(
            "/api/v1/production-units/{$productionUnit->getKey()}/poultry-houses",
            ['name' => 'Planta inválida', 'type' => 'feed', 'bird_capacity' => 100],
        )->assertUnprocessable()
            ->assertJsonValidationErrors('bird_capacity');

        // Verificación: confirma que el registro inválido no se persistió.
        $this->assertDatabaseMissing('poultry_houses', ['production_unit_id' => $productionUnit->getKey()]);
    }

    // Flujo: impide cambiar el tipo de un galpón ya creado.
    public function test_type_is_immutable_after_creation(): void
    {
        // Preparación: crea un galpón avícola y autentica al gestor.
        $poultryHouse = PoultryHouse::factory()->create();
        Sanctum::actingAs($this->userWithPermissions(['poultry-houses.manage']), ['*']);

        // Request: intenta convertir el galpón avícola en una planta de ración.
        $this->patchJson("/api/v1/poultry-houses/{$poultryHouse->getKey()}", [
            'type' => 'feed',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('type');

        // Verificación: conserva el tipo original en la base.
        $this->assertDatabaseHas('poultry_houses', [
            'id' => $poultryHouse->getKey(),
            'type' => 'poultry',
        ]);
    }

    // Flujo: permite inactivar una planta de ración sin consultar ocupación de aves.
    public function test_feed_house_can_become_inactive_with_stock_independently_of_bird_occupancy(): void
    {
        // Preparación: crea una planta y evita que el flujo consulte ocupación de lotes.
        $poultryHouse = PoultryHouse::factory()->feed()->create();
        $this->mock(PoultryHouseOccupancyProvider::class)
            ->shouldNotReceive('occupancyFor');
        Sanctum::actingAs($this->userWithPermissions(['poultry-houses.manage']), ['*']);

        // Request: cambia el estado de la planta a inactivo.
        $this->patchJson("/api/v1/poultry-houses/{$poultryHouse->getKey()}/status", [
            'status' => 'inactive',
        ])->assertOk()
            ->assertJsonPath('data.type', 'feed')
            ->assertJsonPath('data.status', 'inactive');

        // Verificación: confirma la transición persistida.
        $this->assertDatabaseHas('poultry_houses', [
            'id' => $poultryHouse->getKey(),
            'type' => 'feed',
            'status' => 'inactive',
        ]);
    }

    // Flujo: filtra galpones por tipo y no calcula ocupación para plantas de ración.
    public function test_list_filters_feed_houses_and_exposes_null_occupancy(): void
    {
        // Preparación: crea un galpón avícola y una planta en la misma unidad.
        $productionUnit = ProductionUnit::factory()->create();
        PoultryHouse::factory()->for($productionUnit)->create(['name' => 'House A']);
        PoultryHouse::factory()->for($productionUnit)->feed()->create(['name' => 'Planta A']);
        Sanctum::actingAs($this->userWithPermissions(['poultry-houses.view']), ['*']);

        // Request: consulta únicamente las plantas de ración.
        $this->getJson("/api/v1/production-units/{$productionUnit->getKey()}/poultry-houses?type=feed")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'feed')
            ->assertJsonPath('data.0.bird_capacity', null)
            ->assertJsonPath('data.0.current_occupancy', null);
    }

    // Flujo: intenta crear un galpón en una unidad inactiva y verifica el conflicto.
    public function test_returns_409_when_creating_poultry_house_in_inactive_unit(): void
    {
        // Preparación: crea una unidad inactiva y autentica al gestor.
        $unidadProductiva = ProductionUnit::factory()->inactive()->create();
        $actor = $this->userWithPermissions(['poultry-houses.manage']);
        Sanctum::actingAs($actor, ['*']);

        // Acción: intenta registrar un galpón en la unidad inactiva.
        $this->postJson(
            "/api/v1/production-units/{$unidadProductiva->getKey()}/poultry-houses",
            ['name' => 'House A', 'bird_capacity' => 12000],
        )->assertConflict()
            ->assertJsonPath('message', 'Los galpones sólo pueden crearse en una unidad productiva activa.');

        // Verificación: confirma que el galpón no se creó.
        $this->assertDatabaseMissing('poultry_houses', [
            'production_unit_id' => $unidadProductiva->getKey(),
        ]);
    }

    // Flujo: reduce el umbral bajo la ocupación actual y devuelve una alerta con auditoría.
    public function test_capacity_below_current_occupancy_is_saved_with_warning(): void
    {
        // Preparación: crea el galpón, autentica al gestor y fija una ocupación actual.
        $poultryHouse = PoultryHouse::factory()->create(['bird_capacity' => 100]);
        $actor = $this->userWithPermissions(['poultry-houses.manage']);
        Sanctum::actingAs($actor, ['*']);
        $this->app->instance(PoultryHouseOccupancyProvider::class, new class implements PoultryHouseOccupancyProvider
        {
            public function occupancyFor(int $poultryHouseId): int
            {
                return 80;
            }

            /** @param list<int> $poultryHouseIds
             * @return array<int, int>
             */
            public function occupanciesFor(array $poultryHouseIds): array
            {
                return array_fill_keys($poultryHouseIds, 80);
            }

            public function openFlocksCountFor(int $poultryHouseId): int
            {
                return 1;
            }
        });

        // Acción: reduce la capacidad por debajo de la ocupación.
        $this->patchJson("/api/v1/poultry-houses/{$poultryHouse->getKey()}", [
            'bird_capacity' => 79,
        ])->assertOk()
            ->assertJsonPath('data.bird_capacity', 79)
            ->assertJsonPath('data.current_occupancy', 80)
            ->assertJsonPath('data.capacity_exceeded', true)
            ->assertJsonPath('data.excess_quantity', 1)
            ->assertJsonPath('data.capacity_alert', 'La ocupación del galpón supera su capacidad máxima. Aves excedentes: 1.');

        // Verificación: confirma el nuevo límite y la auditoría de la modificación.
        $this->assertDatabaseHas('poultry_houses', [
            'id' => $poultryHouse->getKey(),
            'bird_capacity' => 79,
        ]);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'poultry_house_updated',
            'subject_id' => $poultryHouse->getKey(),
        ]);
    }

    // Flujo: cambia el estado del galpón y repite la transición para verificar auditoría y conflicto.
    public function test_status_transition_is_audited_and_rejects_duplicate_transition(): void
    {
        // Preparación: crea el galpón y autentica al gestor.
        $poultryHouse = PoultryHouse::factory()->create();
        $actor = $this->userWithPermissions(['poultry-houses.manage']);
        Sanctum::actingAs($actor, ['*']);

        $url = "/api/v1/poultry-houses/{$poultryHouse->getKey()}/status";

        // Acción 1: cambia el galpón a mantenimiento.
        $this->patchJson($url, ['status' => 'maintenance'])
            ->assertOk()
            ->assertJsonPath('data.status', 'maintenance');

        // Acción 2: repite la transición ya aplicada.
        $this->patchJson($url, ['status' => 'maintenance'])
            ->assertConflict()
            ->assertJsonPath('message', 'La transición de estado del galpón no está permitida.');

        // Verificación: confirma una sola auditoría por transición válida.
        $this->assertDatabaseCount('activity_log', 1);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'poultry_house_status_changed',
            'subject_id' => $poultryHouse->getKey(),
        ]);
    }

    // Flujo: lista ocupación actual por galpón con una consulta agrupada y la incluye en el detalle.
    public function test_list_and_detail_expose_current_occupancy_from_batch_provider(): void
    {
        // Preparación: crea dos galpones y configura un proveedor que sólo resuelve lotes de IDs.
        $productionUnit = ProductionUnit::factory()->create();
        $firstHouse = PoultryHouse::factory()->for($productionUnit)->create(['name' => 'House A', 'bird_capacity' => 10]);
        $secondHouse = PoultryHouse::factory()->for($productionUnit)->create(['name' => 'House B', 'bird_capacity' => 10]);
        $provider = new class implements PoultryHouseOccupancyProvider
        {
            /** @var list<list<int>> */
            public array $requestedBatches = [];

            public function occupancyFor(int $poultryHouseId): int
            {
                throw new \LogicException('The list query must use the batch occupancy method.');
            }

            /** @param list<int> $poultryHouseIds
             * @return array<int, int>
             */
            public function occupanciesFor(array $poultryHouseIds): array
            {
                $this->requestedBatches[] = $poultryHouseIds;

                return [
                    (int) $this->requestedBatches[0][0] => 10,
                    (int) $this->requestedBatches[0][1] => 20,
                ];
            }

            public function openFlocksCountFor(int $poultryHouseId): int
            {
                return 0;
            }
        };
        $this->app->instance(PoultryHouseOccupancyProvider::class, $provider);
        Sanctum::actingAs($this->userWithPermissions(['poultry-houses.view']), ['*']);

        // Acción: consulta la colección y luego el detalle individual de un galpón.
        $this->getJson("/api/v1/production-units/{$productionUnit->getKey()}/poultry-houses")
            ->assertOk()
            ->assertJsonPath('data.0.id', $firstHouse->getKey())
            ->assertJsonPath('data.0.current_occupancy', 10)
            ->assertJsonPath('data.0.capacity_exceeded', false)
            ->assertJsonPath('data.0.excess_quantity', 0)
            ->assertJsonPath('data.0.capacity_alert', null)
            ->assertJsonPath('data.1.id', $secondHouse->getKey())
            ->assertJsonPath('data.1.current_occupancy', 20)
            ->assertJsonPath('data.1.capacity_exceeded', true)
            ->assertJsonPath('data.1.excess_quantity', 10);

        $this->getJson("/api/v1/poultry-houses/{$secondHouse->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.current_occupancy', 20)
            ->assertJsonPath('data.capacity_alert', 'La ocupación del galpón supera su capacidad máxima. Aves excedentes: 10.');

        // Verificación: prueba una llamada por colección y una llamada batch para el detalle.
        $this->assertSame([
            [(int) $firstHouse->getKey(), (int) $secondHouse->getKey()],
            [(int) $secondHouse->getKey()],
        ], $provider->requestedBatches);
    }

    // Flujo: la alerta depende de la ocupación real y sólo aparece al superar el umbral.
    #[TestWith([99, false, 0, 1])]
    #[TestWith([100, false, 0, 0])]
    #[TestWith([101, true, 1, 0])]
    public function test_capacity_warning_uses_live_occupancy(int $quantity, bool $exceeded, int $excess, int $available): void
    {
        // Preparación: crea un lote en cuarentena y un umbral de cien aves.
        $house = PoultryHouse::factory()->create(['bird_capacity' => 100]);
        Flock::factory()->quarantined()->create([
            'poultry_house_id' => $house->id, 'production_unit_id' => $house->production_unit_id,
            'initial_quantity' => $quantity, 'current_quantity' => $quantity,
        ]);
        Sanctum::actingAs($this->userWithPermissions(['poultry-houses.view']), ['*']);

        // Consulta: verifica el detalle y el listado con el proveedor real de Lotes.
        $this->getJson("/api/v1/poultry-houses/{$house->id}")
            ->assertOk()->assertJsonPath('data.capacity_exceeded', $exceeded)
            ->assertJsonPath('data.excess_quantity', $excess)
            ->assertJsonPath('data.capacity_alert', $exceeded ? 'La ocupación del galpón supera su capacidad máxima. Aves excedentes: 1.' : null);
        $this->getJson("/api/v1/production-units/{$house->production_unit_id}/poultry-houses")
            ->assertOk()->assertJsonPath('data.0.capacity_exceeded', $exceeded);

        // Consulta: la disponibilidad informativa nunca es negativa ni provoca una excepción.
        $operationalHouse = $this->app->make(GetOperationalPoultryHouseQuery::class)->execute($house->id);
        $this->assertSame($available, $operationalHouse->availableCapacity);
    }

    /** @param list<string> $permissions */
    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user;
    }
}
