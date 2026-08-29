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

    // Flujo: crea una unidad productiva y verifica persistencia y auditoría.
    public function test_valid_payload_creates_production_unit_and_audit_entry(): void
    {
        // Preparación: crea la localidad y autentica al actor autorizado.
        $locality = Locality::factory()->create();
        $actor = $this->userWithPermissions(['production-units.manage']);
        Sanctum::actingAs($actor, ['*']);

        // Acción: registra la unidad productiva mediante la API.
        $response = $this->postJson('/api/v1/unidades-productivas', [
            'locality_id' => $locality->getKey(),
            'name' => 'North Farm',
            'latitude' => '-34.901100',
            'longitude' => '-56.164500',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'North Farm')
            ->assertJsonPath('data.status', 'active');

        $productionUnitId = (int) $response->json('data.id');

        // Verificación: confirma datos normalizados y auditoría del alta.
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

    // Flujo: lista y consulta dos unidades con permiso global de lectura.
    public function test_user_with_view_permission_can_access_every_production_unit(): void
    {
        // Preparación: crea dos unidades y autentica al lector autorizado.
        $firstProductionUnit = ProductionUnit::factory()->create(['name' => 'North Farm']);
        $secondProductionUnit = ProductionUnit::factory()->create(['name' => 'South Farm']);
        $actor = $this->userWithPermissions(['production-units.view']);
        Sanctum::actingAs($actor, ['*']);

        // Acción 1: lista todas las unidades productivas accesibles.
        $this->getJson('/api/v1/unidades-productivas')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['name' => 'North Farm'])
            ->assertJsonFragment(['name' => 'South Farm']);

        // Acción 2: consulta la primera unidad por identificador.
        $this->getJson("/api/v1/unidades-productivas/{$firstProductionUnit->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.id', $firstProductionUnit->getKey());

        // Acción 3: consulta la segunda unidad por identificador.
        $this->getJson("/api/v1/unidades-productivas/{$secondProductionUnit->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.id', $secondProductionUnit->getKey());
    }

    // Flujo: autentica un usuario sin permiso y verifica que no puede listar unidades.
    public function test_user_without_view_permission_cannot_access_production_units(): void
    {
        // Preparación: registra el permiso y autentica un usuario sin asignarlo.
        Permission::findOrCreate('production-units.view', 'web');
        Sanctum::actingAs(User::factory()->create(), ['*']);

        // Acción: intenta listar las unidades productivas.
        $this->getJson('/api/v1/unidades-productivas')->assertForbidden();
    }

    // Flujo: autentica a un lector y consulta una unidad inexistente para obtener 404.
    public function test_returns_404_with_a_spanish_message_for_a_missing_production_unit(): void
    {
        // Preparación: autentica al usuario con permiso de lectura.
        Sanctum::actingAs($this->userWithPermissions(['production-units.view']), ['*']);

        // Acción: consulta un identificador inexistente.
        $this->getJson('/api/v1/unidades-productivas/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'El recurso solicitado no existe.');
    }

    // Flujo: envía coordenadas inválidas y verifica validación sin persistencia.
    public function test_returns_422_when_coordinates_are_outside_supported_ranges(): void
    {
        // Preparación: autentica al usuario con permiso de gestión.
        Sanctum::actingAs($this->userWithPermissions(['production-units.manage']), ['*']);

        // Acción: intenta crear una unidad con coordenadas fuera de rango.
        $this->postJson('/api/v1/unidades-productivas', [
            'locality_id' => Locality::factory()->create()->getKey(),
            'name' => 'Invalid Coordinates',
            'latitude' => 91,
            'longitude' => -181,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude']);

        // Verificación: confirma que la unidad inválida no se guardó.
        $this->assertDatabaseMissing('production_units', ['normalized_name' => 'invalid coordinates']);
    }

    // Flujo: intenta insertar coordenadas inválidas directamente y verifica la restricción PostgreSQL.
    public function test_postgresql_constraints_reject_invalid_coordinates_outside_http(): void
    {
        // Preparación: espera la excepción generada por la restricción de base de datos.
        $this->expectException(QueryException::class);

        // Acción: persiste una unidad con latitud fuera del límite permitido.
        ProductionUnit::query()->create([
            'locality_id' => Locality::factory()->create()->getKey(),
            'name' => 'Constraint Test',
            'latitude' => '90.000001',
            'longitude' => '0.000000',
            'status' => 'active',
        ]);
    }

    // Flujo: intenta desactivar una unidad con un galpón activo y verifica el conflicto.
    public function test_deactivation_returns_409_while_a_poultry_house_is_active(): void
    {
        // Preparación: crea la unidad, un galpón activo y el actor autorizado.
        $productionUnit = ProductionUnit::factory()->create();
        PoultryHouse::factory()->for($productionUnit)->create();
        $actor = $this->userWithPermissions(['production-units.manage']);
        Sanctum::actingAs($actor, ['*']);

        // Acción: solicita la desactivación de la unidad productiva.
        $this->patchJson("/api/v1/unidades-productivas/{$productionUnit->getKey()}/estado", [
            'status' => 'inactive',
        ])->assertConflict()
            ->assertJsonPath('message', 'Una unidad productiva con galpones activos no puede desactivarse.');

        // Verificación: confirma que la unidad permanece activa.
        $this->assertDatabaseHas('production_units', [
            'id' => $productionUnit->getKey(),
            'status' => 'active',
        ]);
    }

    // Flujo: desactiva una unidad cuyos galpones ya están inactivos y verifica auditoría.
    public function test_deactivation_succeeds_after_all_poultry_houses_are_inactive(): void
    {
        // Preparación: crea la unidad con todos sus galpones inactivos.
        $productionUnit = ProductionUnit::factory()->create();
        PoultryHouse::factory()->for($productionUnit)->create([
            'status' => PoultryHouseStatus::Inactive,
        ]);
        $actor = $this->userWithPermissions(['production-units.manage']);
        Sanctum::actingAs($actor, ['*']);

        // Acción: cambia la unidad productiva a estado inactivo.
        $this->patchJson("/api/v1/unidades-productivas/{$productionUnit->getKey()}/estado", [
            'status' => 'inactive',
        ])->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        // Verificación: confirma la auditoría del cambio de estado.
        $this->assertDatabaseHas('activity_log', [
            'event' => 'production_unit_status_changed',
            'subject_id' => $productionUnit->getKey(),
            'up_id' => $productionUnit->getKey(),
        ]);
    }

    // Flujo: fuerza un fallo de auditoría y verifica rollback de la unidad productiva.
    public function test_failed_audit_rolls_back_production_unit(): void
    {
        // Preparación: crea contexto y reemplaza el grabador por uno que falla.
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
            // Acción: intenta crear la unidad productiva con auditoría fallida.
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

        // Verificación: confirma que la unidad no se persistió.
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
