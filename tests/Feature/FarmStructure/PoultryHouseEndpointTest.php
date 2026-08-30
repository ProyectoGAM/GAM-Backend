<?php

namespace Tests\Feature\FarmStructure;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use App\Modules\FarmStructure\Application\PublicApi\Contracts\PoultryHouseOccupancyProvider;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
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
            "/api/v1/unidades-productivas/{$unidadProductiva->getKey()}/galpones",
            ['nombre' => 'House A', 'capacidad_aves' => 12000],
        )->assertCreated()
            ->assertJsonPath('data.capacidad_aves', 12000)
            ->assertJsonPath('data.estado', 'operational');

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

    // Flujo: intenta crear un galpón en una unidad inactiva y verifica el conflicto.
    public function test_returns_409_when_creating_poultry_house_in_inactive_unit(): void
    {
        // Preparación: crea una unidad inactiva y autentica al gestor.
        $unidadProductiva = ProductionUnit::factory()->inactive()->create();
        $actor = $this->userWithPermissions(['poultry-houses.manage']);
        Sanctum::actingAs($actor, ['*']);

        // Acción: intenta registrar un galpón en la unidad inactiva.
        $this->postJson(
            "/api/v1/unidades-productivas/{$unidadProductiva->getKey()}/galpones",
            ['nombre' => 'House A', 'capacidad_aves' => 12000],
        )->assertConflict()
            ->assertJsonPath('message', 'Los galpones sólo pueden crearse en una unidad productiva activa.');

        // Verificación: confirma que el galpón no se creó.
        $this->assertDatabaseMissing('poultry_houses', [
            'production_unit_id' => $unidadProductiva->getKey(),
        ]);
    }

    // Flujo: reduce capacidad por debajo de la ocupación y verifica conflicto sin cambios.
    public function test_returns_409_and_preserves_capacity_when_current_occupancy_is_higher(): void
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
        });

        // Acción: intenta reducir la capacidad por debajo de la ocupación.
        $this->patchJson("/api/v1/galpones/{$poultryHouse->getKey()}", [
            'capacidad_aves' => 79,
        ])->assertConflict()
            ->assertJsonPath('message', 'La capacidad de aves no puede ser menor que la ocupación actual.');

        // Verificación: confirma capacidad original y ausencia de auditoría.
        $this->assertDatabaseHas('poultry_houses', [
            'id' => $poultryHouse->getKey(),
            'bird_capacity' => 100,
        ]);
        $this->assertDatabaseMissing('activity_log', [
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

        $url = "/api/v1/galpones/{$poultryHouse->getKey()}/estado";

        // Acción 1: cambia el galpón a mantenimiento.
        $this->patchJson($url, ['estado' => 'maintenance'])
            ->assertOk()
            ->assertJsonPath('data.estado', 'maintenance');

        // Acción 2: repite la transición ya aplicada.
        $this->patchJson($url, ['estado' => 'maintenance'])
            ->assertConflict()
            ->assertJsonPath('message', 'La transición de estado del galpón no está permitida.');

        // Verificación: confirma una sola auditoría por transición válida.
        $this->assertDatabaseCount('activity_log', 1);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'poultry_house_status_changed',
            'subject_id' => $poultryHouse->getKey(),
        ]);
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
