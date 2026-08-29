<?php

namespace Tests\Feature\FarmStructure;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Geography\Locality;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use App\Modules\FarmStructure\Application\Actions\CreateProductionUnitAction;
use App\Modules\FarmStructure\Domain\Enums\PoultryHouseStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class ProductionUnitEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_valid_payload_creates_production_unit_and_audit_entry(): void
    {
        $locality = Locality::factory()->create();
        $actor = $this->userWithPermissions(['production-units.manage']);
        Sanctum::actingAs($actor, ['*']);

        $response = $this->postJson('/api/v1/unidades-productivas', [
            'locality_id' => $locality->getKey(),
            'name' => 'North Farm',
            'latitude' => '-34.901100',
            'longitude' => '-56.164500',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'North Farm')
            ->assertJsonPath('data.status', 'active');

        $productionUnitId = (int) $response->json('data.id');

        $this->assertDatabaseHas('production_units', [
            'id' => $productionUnitId,
            'locality_id' => $locality->getKey(),
            'normalized_name' => 'north farm',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'production_unit_created',
            'subject_id' => $productionUnitId,
            'causer_id' => $actor->getKey(),
            'up_id' => $productionUnitId,
        ]);
    }

    public function test_user_with_view_permission_can_access_every_production_unit(): void
    {
        $firstProductionUnit = ProductionUnit::factory()->create(['name' => 'North Farm']);
        $secondProductionUnit = ProductionUnit::factory()->create(['name' => 'South Farm']);
        $actor = $this->userWithPermissions(['production-units.view']);
        Sanctum::actingAs($actor, ['*']);

        $this->getJson('/api/v1/unidades-productivas')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['name' => 'North Farm'])
            ->assertJsonFragment(['name' => 'South Farm']);

        $this->getJson("/api/v1/unidades-productivas/{$firstProductionUnit->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.id', $firstProductionUnit->getKey());

        $this->getJson("/api/v1/unidades-productivas/{$secondProductionUnit->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.id', $secondProductionUnit->getKey());
    }

    public function test_user_without_view_permission_cannot_access_production_units(): void
    {
        Permission::findOrCreate('production-units.view', 'web');
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->getJson('/api/v1/unidades-productivas')->assertForbidden();
    }

    public function test_returns_404_with_a_spanish_message_for_a_missing_production_unit(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['production-units.view']), ['*']);

        $this->getJson('/api/v1/unidades-productivas/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'El recurso solicitado no existe.');
    }

    public function test_returns_422_when_coordinates_are_outside_supported_ranges(): void
    {
        Sanctum::actingAs($this->userWithPermissions(['production-units.manage']), ['*']);

        $this->postJson('/api/v1/unidades-productivas', [
            'locality_id' => Locality::factory()->create()->getKey(),
            'name' => 'Invalid Coordinates',
            'latitude' => 91,
            'longitude' => -181,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude']);

        $this->assertDatabaseMissing('production_units', ['normalized_name' => 'invalid coordinates']);
    }

    public function test_postgresql_constraints_reject_invalid_coordinates_outside_http(): void
    {
        $this->expectException(QueryException::class);

        ProductionUnit::query()->create([
            'locality_id' => Locality::factory()->create()->getKey(),
            'name' => 'Constraint Test',
            'latitude' => '90.000001',
            'longitude' => '0.000000',
            'status' => 'active',
        ]);
    }

    public function test_deactivation_returns_409_while_a_poultry_house_is_active(): void
    {
        $productionUnit = ProductionUnit::factory()->create();
        PoultryHouse::factory()->for($productionUnit)->create();
        $actor = $this->userWithPermissions(['production-units.manage']);
        Sanctum::actingAs($actor, ['*']);

        $this->patchJson("/api/v1/unidades-productivas/{$productionUnit->getKey()}/estado", [
            'status' => 'inactive',
        ])->assertConflict()
            ->assertJsonPath('message', 'Una unidad productiva con galpones activos no puede desactivarse.');

        $this->assertDatabaseHas('production_units', [
            'id' => $productionUnit->getKey(),
            'status' => 'active',
        ]);
    }

    public function test_deactivation_succeeds_after_all_poultry_houses_are_inactive(): void
    {
        $productionUnit = ProductionUnit::factory()->create();
        PoultryHouse::factory()->for($productionUnit)->create([
            'status' => PoultryHouseStatus::Inactive,
        ]);
        $actor = $this->userWithPermissions(['production-units.manage']);
        Sanctum::actingAs($actor, ['*']);

        $this->patchJson("/api/v1/unidades-productivas/{$productionUnit->getKey()}/estado", [
            'status' => 'inactive',
        ])->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('activity_log', [
            'event' => 'production_unit_status_changed',
            'subject_id' => $productionUnit->getKey(),
            'up_id' => $productionUnit->getKey(),
        ]);
    }

    public function test_failed_audit_rolls_back_production_unit(): void
    {
        $locality = Locality::factory()->create();
        $actor = User::factory()->create();
        $this->app->instance(AuditRecorder::class, new class implements AuditRecorder
        {
            public function record(AuditEntryData $entry): void
            {
                throw new RuntimeException('Audit storage failed.');
            }
        });

        try {
            $this->app->make(CreateProductionUnitAction::class)->execute([
                'locality_id' => (int) $locality->getKey(),
                'name' => 'Rolled Back Farm',
                'latitude' => '-34.901100',
                'longitude' => '-56.164500',
            ], $actor);

            $this->fail('The operation should fail when audit storage fails.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage failed.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('production_units', [
            'normalized_name' => 'rolled back farm',
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
