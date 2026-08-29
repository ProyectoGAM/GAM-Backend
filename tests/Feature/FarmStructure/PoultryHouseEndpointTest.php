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

    public function test_valid_payload_creates_poultry_house_without_mutating_capacity(): void
    {
        $productionUnit = ProductionUnit::factory()->create();
        $actor = $this->userWithPermissions(['poultry-houses.manage']);
        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson(
            "/api/v1/unidades-productivas/{$productionUnit->getKey()}/galpones",
            ['name' => 'House A', 'bird_capacity' => 12000],
        )->assertCreated()
            ->assertJsonPath('data.bird_capacity', 12000)
            ->assertJsonPath('data.status', 'operational');

        $poultryHouseId = (int) $response->json('data.id');

        $this->assertDatabaseHas('poultry_houses', [
            'id' => $poultryHouseId,
            'production_unit_id' => $productionUnit->getKey(),
            'bird_capacity' => 12000,
        ]);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'poultry_house_created',
            'subject_id' => $poultryHouseId,
            'up_id' => $productionUnit->getKey(),
        ]);
    }

    public function test_returns_409_when_creating_poultry_house_in_inactive_unit(): void
    {
        $productionUnit = ProductionUnit::factory()->inactive()->create();
        $actor = $this->userWithPermissions(['poultry-houses.manage']);
        Sanctum::actingAs($actor, ['*']);

        $this->postJson(
            "/api/v1/unidades-productivas/{$productionUnit->getKey()}/galpones",
            ['name' => 'House A', 'bird_capacity' => 12000],
        )->assertConflict()
            ->assertJsonPath('message', 'Los galpones sólo pueden crearse en una unidad productiva activa.');

        $this->assertDatabaseMissing('poultry_houses', [
            'production_unit_id' => $productionUnit->getKey(),
        ]);
    }

    public function test_returns_409_and_preserves_capacity_when_current_occupancy_is_higher(): void
    {
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

        $this->patchJson("/api/v1/galpones/{$poultryHouse->getKey()}", [
            'bird_capacity' => 79,
        ])->assertConflict()
            ->assertJsonPath('message', 'La capacidad de aves no puede ser menor que la ocupación actual.');

        $this->assertDatabaseHas('poultry_houses', [
            'id' => $poultryHouse->getKey(),
            'bird_capacity' => 100,
        ]);
        $this->assertDatabaseMissing('activity_log', [
            'event' => 'poultry_house_updated',
            'subject_id' => $poultryHouse->getKey(),
        ]);
    }

    public function test_status_transition_is_audited_and_rejects_duplicate_transition(): void
    {
        $poultryHouse = PoultryHouse::factory()->create();
        $actor = $this->userWithPermissions(['poultry-houses.manage']);
        Sanctum::actingAs($actor, ['*']);

        $url = "/api/v1/galpones/{$poultryHouse->getKey()}/estado";

        $this->patchJson($url, ['status' => 'maintenance'])
            ->assertOk()
            ->assertJsonPath('data.status', 'maintenance');

        $this->patchJson($url, ['status' => 'maintenance'])
            ->assertConflict()
            ->assertJsonPath('message', 'La transición de estado del galpón no está permitida.');

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
