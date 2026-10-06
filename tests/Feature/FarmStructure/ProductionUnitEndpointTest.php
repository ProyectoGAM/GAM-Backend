<?php

namespace Tests\Feature\FarmStructure;

use App\Actions\FarmStructure\CreateProductionUnitAction;
use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\FarmStructure\PoultryHouseStatus;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Geography\Locality;
use App\Models\User;
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
        $localidad = Locality::factory()->create();
        $actor = $this->userWithPermissions(['production-units.manage']);
        Sanctum::actingAs($actor, ['*']);

        // Acción: registra la unidad productiva mediante la API.
        $response = $this->postJson('/api/v1/production-units', [
            'locality_id' => $localidad->getKey(),
            'name' => 'North Farm',
            'address' => 'Ruta 5, kilómetro 24, Las Piedras',
            'latitude' => '-34.901100',
            'longitude' => '-56.164500',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'North Farm')
            ->assertJsonPath('data.address', 'Ruta 5, kilómetro 24, Las Piedras')
            ->assertJsonPath('data.status', 'active');

        $productionUnitId = (int) $response->json('data.id');

        // Verificación: confirma datos normalizados y auditoría del alta.
        $this->assertDatabaseHas('production_units', [
            'id' => $productionUnitId,
            'locality_id' => $localidad->getKey(),
            'normalized_name' => 'north farm',
            'address' => 'Ruta 5, kilómetro 24, Las Piedras',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'production_unit_created',
            'subject_id' => $productionUnitId,
            'causer_id' => $actor->getKey(),
            'up_id' => $productionUnitId,
        ]);

        // Verificación: confirma que la auditoría conserva la dirección del punto.
        $entry = AuditEntry::query()->where('event', 'production_unit_created')->firstOrFail();
        $this->assertSame('Ruta 5, kilómetro 24, Las Piedras', $entry->getProperty('subject_snapshot')['address']);
        $this->assertSame('Ruta 5, kilómetro 24, Las Piedras', $entry->attribute_changes->get('new')['address']);
    }

    // Flujo: permite registrar una unidad rural sin localidad catalogada y conserva su contrato nulo.
    public function test_rural_production_unit_can_be_created_without_a_catalog_locality(): void
    {
        // Preparación: autentica al gestor y define una ubicación válida dentro de Uruguay.
        Sanctum::actingAs($this->userWithPermissions(['production-units.manage']), ['*']);
        $payload = [
            'locality_id' => null,
            'name' => 'Rural Farm Without Locality',
            'address' => 'Paraje rural, Durazno',
            'latitude' => '-33.413100',
            'longitude' => '-56.500000',
        ];

        // Acción: crea la unidad sin localidad exacta en el catálogo.
        $response = $this->postJson('/api/v1/production-units', $payload)
            ->assertCreated()
            ->assertJsonPath('data.locality_id', null)
            ->assertJsonPath('data.locality', null);
        $productionUnitId = (int) $response->json('data.id');

        // Verificación: persiste la ubicación sin localidad y audita ese estado.
        $this->assertDatabaseHas('production_units', [
            'id' => $productionUnitId,
            'locality_id' => null,
            'name' => 'Rural Farm Without Locality',
        ]);
        $entry = AuditEntry::query()->where('event', 'production_unit_created')->firstOrFail();
        $this->assertNull($entry->getProperty('subject_snapshot')['locality_id']);

        // Acción: intenta registrar otra unidad sin localidad con el mismo nombre normalizado.
        $secondResponse = $this->postJson('/api/v1/production-units', [
            ...$payload,
            'name' => 'Second Rural Farm',
            'address' => 'Paraje rural diferente, Durazno',
        ])->assertCreated();
        $this->patchJson('/api/v1/production-units/'.$secondResponse->json('data.id'), [
            'name' => ' rural farm without locality ',
        ])->assertOk();
        $this->assertDatabaseCount('production_units', 2);
    }

    // Flujo: conserva la unicidad de nombres dentro de una localidad conocida.
    public function test_production_unit_name_remains_unique_within_a_known_locality(): void
    {
        // Preparación: autentica al gestor y asigna una localidad del catálogo.
        Sanctum::actingAs($this->userWithPermissions(['production-units.manage']), ['*']);
        $localidad = Locality::factory()->create();
        $payload = [
            'locality_id' => $localidad->getKey(),
            'name' => 'Known Locality Farm',
            'address' => 'Ruta 5, localidad conocida',
            'latitude' => '-34.901100',
            'longitude' => '-56.164500',
        ];

        // Acción: registra el nombre y rechaza su duplicado normalizado en esa localidad.
        $this->postJson('/api/v1/production-units', $payload)->assertCreated();
        $this->postJson('/api/v1/production-units', [...$payload, 'name' => ' known locality farm '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        // Verificación: también impide trasladar otro nombre duplicado a esa localidad.
        $otherProductionUnit = ProductionUnit::factory()->create([
            'name' => 'Another Known Farm',
        ]);
        $this->patchJson('/api/v1/production-units/'.$otherProductionUnit->getKey(), [
            'locality_id' => $localidad->getKey(),
            'name' => 'known locality farm',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    // Flujo: reutiliza la validación oficial para prevalidar puntos y rechaza ubicaciones fuera del país.
    public function test_location_prevalidation_returns_no_content_inside_uruguay_and_rejects_outside_points(): void
    {
        // Preparación: autentica al gestor que puede crear y editar unidades productivas.
        Sanctum::actingAs($this->userWithPermissions(['production-units.manage']), ['*']);

        // Acción: valida un punto de Durazno y rechaza otro fuera de Uruguay.
        $this->postJson('/api/v1/production-units/validate-location', [
            'latitude' => '-33.413100',
            'longitude' => '-56.500000',
        ])->assertNoContent();
        $this->postJson('/api/v1/production-units/validate-location', [
            'latitude' => '0',
            'longitude' => '0',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude']);

        // Verificación: el mismo límite nacional se aplica al alta de una unidad.
        $this->postJson('/api/v1/production-units', [
            'name' => 'Outside Uruguay',
            'address' => 'No válida',
            'latitude' => '0',
            'longitude' => '0',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude']);
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
        $this->getJson('/api/v1/production-units')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['name' => 'North Farm'])
            ->assertJsonFragment(['name' => 'South Farm']);

        // Acción 2: consulta la primera unidad por identificador.
        $this->getJson("/api/v1/production-units/{$firstProductionUnit->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.id', $firstProductionUnit->getKey());

        // Acción 3: consulta la segunda unidad por identificador.
        $this->getJson("/api/v1/production-units/{$secondProductionUnit->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.id', $secondProductionUnit->getKey());
    }

    // Flujo: autentica un usuario sin permiso y verifica que no puede listar unidades.
    public function test_user_without_view_permission_cannot_access_unidades_productivas(): void
    {
        // Preparación: registra el permiso y autentica un usuario sin asignarlo.
        Permission::findOrCreate('production-units.view', 'web');
        Sanctum::actingAs(User::factory()->create(), ['*']);

        // Acción: intenta listar las unidades productivas.
        $this->getJson('/api/v1/production-units')->assertForbidden();
    }

    // Flujo: autentica a un lector y consulta una unidad inexistente para obtener 404.
    public function test_returns_404_with_a_spanish_message_for_a_missing_production_unit(): void
    {
        // Preparación: autentica al usuario con permiso de lectura.
        Sanctum::actingAs($this->userWithPermissions(['production-units.view']), ['*']);

        // Acción: consulta un identificador inexistente.
        $this->getJson('/api/v1/production-units/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'El recurso solicitado no existe.');
    }

    // Flujo: envía coordenadas inválidas y verifica validación sin persistencia.
    public function test_returns_422_when_coordinates_are_outside_supported_ranges(): void
    {
        // Preparación: autentica al usuario con permiso de gestión.
        Sanctum::actingAs($this->userWithPermissions(['production-units.manage']), ['*']);

        // Acción: intenta crear una unidad con coordenadas fuera de rango.
        $this->postJson('/api/v1/production-units', [
            'locality_id' => Locality::factory()->create()->getKey(),
            'name' => 'Invalid Coordinates',
            'latitude' => 91,
            'longitude' => -181,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude']);

        // Verificación: confirma que la unidad inválida no se guardó.
        $this->assertDatabaseMissing('production_units', ['normalized_name' => 'invalid coordinates']);
    }

    // Flujo: rechaza el alta sin una dirección visible y acotada.
    public function test_create_requires_a_non_empty_bounded_address(): void
    {
        // Preparación: autentica al usuario autorizado y prepara los datos geográficos.
        Sanctum::actingAs($this->userWithPermissions(['production-units.manage']), ['*']);
        $payload = [
            'locality_id' => Locality::factory()->create()->getKey(),
            'name' => 'Address Required Farm',
            'latitude' => '-34.901100',
            'longitude' => '-56.164500',
        ];

        // Acción 1: omite la dirección obligatoria.
        $this->postJson('/api/v1/production-units', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['address']);

        // Acción 2: envía una dirección vacía y otra que supera el límite.
        $this->postJson('/api/v1/production-units', [...$payload, 'address' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['address']);
        $this->postJson('/api/v1/production-units', [...$payload, 'address' => str_repeat('A', 501)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['address']);
    }

    // Flujo: rechaza coordenadas que desbordan el rango numérico finito.
    public function test_create_rejects_non_finite_coordinates(): void
    {
        // Preparación: autentica al gestor y crea la localidad de la solicitud.
        Sanctum::actingAs($this->userWithPermissions(['production-units.manage']), ['*']);

        // Acción: envía una cadena numérica que se convierte en infinito.
        $this->postJson('/api/v1/production-units', [
            'locality_id' => Locality::factory()->create()->getKey(),
            'name' => 'Non Finite Farm',
            'address' => 'Ruta 5, Uruguay',
            'latitude' => '1e999',
            'longitude' => '-56.164500',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude']);
    }

    // Flujo: conserva una unidad histórica sin dirección si no cambia el punto.
    public function test_legacy_unit_without_address_can_be_read_and_updated_without_changing_coordinates(): void
    {
        // Preparación: crea una unidad anterior a la persistencia de direcciones.
        $productionUnit = ProductionUnit::factory()->create([
            'address' => null,
            'latitude' => '0.000000',
            'longitude' => '0.000000',
        ]);
        Sanctum::actingAs($this->userWithPermissions(['production-units.manage', 'production-units.view']), ['*']);

        // Acción 1: consulta la unidad histórica y confirma que la dirección es nula.
        $this->getJson("/api/v1/production-units/{$productionUnit->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.address', null)
            ->assertJsonPath('data.latitude', '0.000000')
            ->assertJsonPath('data.longitude', '0.000000');

        // Acción 2: edita el nombre omitiendo dirección y coordenadas.
        $this->patchJson("/api/v1/production-units/{$productionUnit->getKey()}", [
            'name' => 'Historic Farm Updated',
        ])->assertOk()
            ->assertJsonPath('data.address', null)
            ->assertJsonPath('data.name', 'Historic Farm Updated');

        // Verificación: confirma que el punto histórico permanece intacto.
        $this->assertDatabaseHas('production_units', [
            'id' => $productionUnit->getKey(),
            'address' => null,
            'latitude' => '0.000000',
            'longitude' => '0.000000',
        ]);
    }

    // Flujo: exige dirección al cambiar coordenadas y guarda etiqueta y punto juntos.
    public function test_coordinate_changes_require_and_persist_a_valid_address(): void
    {
        // Preparación: crea una unidad histórica sin dirección y autentica al gestor.
        $productionUnit = ProductionUnit::factory()->create([
            'address' => null,
            'latitude' => '-34.901100',
            'longitude' => '-56.164500',
        ]);
        Sanctum::actingAs($this->userWithPermissions(['production-units.manage']), ['*']);

        // Acción 1: intenta cambiar el punto sin enviar una dirección.
        $this->patchJson("/api/v1/production-units/{$productionUnit->getKey()}", [
            'latitude' => '-34.910000',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['address']);

        // Verificación: confirma que la ubicación no cambió tras el rechazo.
        $this->assertDatabaseHas('production_units', [
            'id' => $productionUnit->getKey(),
            'address' => null,
            'latitude' => '-34.901100',
            'longitude' => '-56.164500',
        ]);

        // Acción 2: confirma el punto modificado con una dirección válida.
        $this->patchJson("/api/v1/production-units/{$productionUnit->getKey()}", [
            'address' => 'Camino rural, Canelones',
            'latitude' => '-34.910000',
        ])->assertOk()
            ->assertJsonPath('data.address', 'Camino rural, Canelones')
            ->assertJsonPath('data.latitude', '-34.910000');

        // Verificación: confirma que la auditoría registra la etiqueta corregida.
        $entry = AuditEntry::query()->where('event', 'production_unit_updated')->firstOrFail();
        $this->assertSame('Camino rural, Canelones', $entry->getProperty('subject_snapshot')['address']);

        // Acción 3: intenta mover el punto fuera del territorio uruguayo.
        $this->patchJson("/api/v1/production-units/{$productionUnit->getKey()}", [
            'address' => 'Ubicación en otro país',
            'latitude' => '0',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude']);

        // Verificación: conserva la selección válida anterior tras el rechazo.
        $this->assertDatabaseHas('production_units', [
            'id' => $productionUnit->getKey(),
            'address' => 'Camino rural, Canelones',
            'latitude' => '-34.910000',
            'longitude' => '-56.164500',
        ]);
    }

    // Flujo: revierte ubicación, datos y auditoría si falla la transición de estado.
    public function test_failed_status_transition_rolls_back_unit_update_and_audit(): void
    {
        // Preparación: crea la unidad activa con un galpón que impide inactivarla.
        $productionUnit = ProductionUnit::factory()->create([
            'name' => 'Farm Before Edit',
            'address' => 'Dirección anterior',
            'latitude' => '-34.901100',
            'longitude' => '-56.164500',
        ]);
        PoultryHouse::factory()->for($productionUnit)->create();
        Sanctum::actingAs($this->userWithPermissions(['production-units.manage']), ['*']);

        // Acción: modifica datos y ubicación junto con una transición inválida.
        $this->patchJson("/api/v1/production-units/{$productionUnit->getKey()}", [
            'name' => 'Farm After Edit',
            'address' => 'Dirección nueva',
            'latitude' => '-34.910000',
            'status' => 'inactive',
        ])->assertConflict()
            ->assertJsonPath('message', 'Una unidad productiva con galpones activos no puede desactivarse.');

        // Verificación: confirma que no quedó ninguna mutación parcial ni auditoría.
        $this->assertDatabaseHas('production_units', [
            'id' => $productionUnit->getKey(),
            'name' => 'Farm Before Edit',
            'address' => 'Dirección anterior',
            'latitude' => '-34.901100',
            'longitude' => '-56.164500',
            'status' => 'active',
        ]);
        $this->assertDatabaseMissing('activity_log', [
            'event' => 'production_unit_updated',
            'subject_id' => $productionUnit->getKey(),
        ]);
        $this->assertDatabaseMissing('activity_log', [
            'event' => 'production_unit_status_changed',
            'subject_id' => $productionUnit->getKey(),
        ]);
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
        $unidadProductiva = ProductionUnit::factory()->create();
        PoultryHouse::factory()->for($unidadProductiva)->create();
        $actor = $this->userWithPermissions(['production-units.manage']);
        Sanctum::actingAs($actor, ['*']);

        // Acción: solicita la desactivación de la unidad productiva.
        $this->patchJson("/api/v1/production-units/{$unidadProductiva->getKey()}/status", [
            'status' => 'inactive',
        ])->assertConflict()
            ->assertJsonPath('message', 'Una unidad productiva con galpones activos no puede desactivarse.');

        // Verificación: confirma que la unidad permanece activa.
        $this->assertDatabaseHas('production_units', [
            'id' => $unidadProductiva->getKey(),
            'status' => 'active',
        ]);
    }

    // Flujo: desactiva una unidad cuyos galpones ya están inactivos y verifica auditoría.
    public function test_deactivation_succeeds_after_all_galpones_are_inactive(): void
    {
        // Preparación: crea la unidad con todos sus galpones inactivos.
        $unidadProductiva = ProductionUnit::factory()->create();
        PoultryHouse::factory()->for($unidadProductiva)->create([
            'status' => PoultryHouseStatus::Inactive,
        ]);
        $actor = $this->userWithPermissions(['production-units.manage']);
        Sanctum::actingAs($actor, ['*']);

        // Acción: cambia la unidad productiva a estado inactivo.
        $this->patchJson("/api/v1/production-units/{$unidadProductiva->getKey()}/status", [
            'status' => 'inactive',
        ])->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        // Verificación: confirma la auditoría del cambio de estado.
        $this->assertDatabaseHas('activity_log', [
            'event' => 'production_unit_status_changed',
            'subject_id' => $unidadProductiva->getKey(),
            'up_id' => $unidadProductiva->getKey(),
        ]);
    }

    // Flujo: fuerza un fallo de auditoría y verifica rollback de la unidad productiva.
    public function test_failed_audit_rolls_back_production_unit(): void
    {
        // Preparación: crea contexto y reemplaza el grabador por uno que falla.
        $localidad = Locality::factory()->create();
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
                'locality_id' => (int) $localidad->getKey(),
                'name' => 'Rolled Back Farm',
                'address' => 'Ruta 5, Uruguay',
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
