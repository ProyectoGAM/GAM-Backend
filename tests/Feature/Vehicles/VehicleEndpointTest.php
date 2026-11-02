<?php

namespace Tests\Feature\Vehicles;

use App\Models\Deliveries\Delivery;
use App\Models\User;
use App\Models\Vehicles\Vehicle;
use App\Models\Vehicles\VehicleUsage;
use App\Services\IdentityAndAccess\PinHasher;
use Database\Seeders\IdentityPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class VehicleEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @comment Preparación: crea una cuenta administrativa con permisos del catálogo de flota. */
    private function administrator(): User
    {
        $this->seed(IdentityPermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);
        Sanctum::actingAs($admin, ['api:access']);

        return $admin;
    }

    /** @comment Preparación: registra un repartidor con permisos de salida y PIN de prueba. */
    private function driver(): User
    {
        $this->seed(IdentityPermissionSeeder::class);
        config(['identity.pin.pepper' => 'vehicle-test-pepper']);
        $driver = User::factory()->create(['pin_hash' => app(PinHasher::class)->hash('0007'), 'pin_enabled' => true]);
        $driver->syncRoles(['delivery']);
        Sanctum::actingAs($driver, ['api:access']);

        return $driver;
    }

    /** Alta, matrícula normalizada y exclusión de vehículos no operativos del selector. */
    public function test_admin_can_create_vehicle_and_driver_sees_only_available_vehicles(): void
    {
        // Preparación: crea la flota y prepara la sesión del administrador.
        $this->administrator();

        // Acción: registra una ficha con matrícula que contiene separadores.
        $vehicle = $this->postJson('/api/v1/vehiculos', ['plate' => 'ab-123 cd', 'make' => 'Fiat', 'model' => 'Fiorino', 'capacity_kg' => '600.00'])
            ->assertCreated()->assertJsonPath('data.plate', 'AB-123 CD')->assertJsonPath('data.status', 'active')->json('data.id');
        $this->assertDatabaseHas('vehicles', ['public_id' => $vehicle, 'plate_normalized' => 'AB123CD']);
        // Comprobación: el historial inicial conserva el estado asignado por defecto.
        $this->assertDatabaseHas('vehicle_status_changes', [
            'vehicle_id' => Vehicle::query()->where('public_id', $vehicle)->sole()->getKey(),
            'from_status' => null,
            'to_status' => 'active',
        ]);

        // Preparación: agrega una unidad fuera de servicio que no debe entrar al selector.
        $unavailable = Vehicle::factory()->create(['status' => 'out_of_service', 'status_reason' => 'Reparación']);

        // Consulta: el repartidor sólo recibe el vehículo habilitado para iniciar.
        $this->driver();
        $this->getJson('/api/v1/vehiculos/disponibles')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $vehicle);
        $this->assertNotSame($vehicle, $unavailable->public_id);
    }

    /** Lecturas manuales idempotentes y orden cronológico del contador. */
    public function test_manual_meter_reading_is_idempotent_and_rejects_a_lower_reading(): void
    {
        // Preparación: crea vehículo y administrador con permiso de registrar lecturas.
        $this->administrator();
        $vehicle = Vehicle::factory()->create();
        $firstKey = (string) Str::uuid();

        // Acción: registra y repite la misma lectura con una clave estable.
        $payload = ['meter_type' => 'odometer', 'value' => '1200.0', 'recorded_at' => '2026-10-20T10:00:00-03:00'];
        $this->postJson('/api/v1/vehiculos/'.$vehicle->public_id.'/lecturas', $payload, ['Idempotency-Key' => $firstKey])->assertCreated();
        $this->postJson('/api/v1/vehiculos/'.$vehicle->public_id.'/lecturas', $payload, ['Idempotency-Key' => $firstKey])->assertCreated();
        $this->assertDatabaseCount('vehicle_meter_readings', 1);

        // Validación: no permite ingresar una lectura más reciente menor al contador previo.
        $this->postJson('/api/v1/vehiculos/'.$vehicle->public_id.'/lecturas', ['meter_type' => 'odometer', 'value' => '1199.0', 'recorded_at' => '2026-10-21T10:00:00-03:00'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('value');
    }

    /** Advertencias de service no bloquean; uso inmovilizante y estado fuera de servicio sí. */
    public function test_due_service_warns_but_immobilization_and_out_of_service_block_selection(): void
    {
        // Preparación: registra un vehículo con un service vencido por fecha.
        $admin = $this->administrator();
        $vehicle = Vehicle::factory()->create();
        $this->postJson('/api/v1/vehiculos/'.$vehicle->public_id.'/planes-mantenimiento', [
            'task' => 'Cambio de aceite', 'interval_days' => 180, 'next_due_at' => now()->subDay()->toDateString(),
        ])->assertCreated();

        // Comprobación: la advertencia informativa no bloquea una nueva salida.
        $driver = $this->driver();
        $this->getJson('/api/v1/vehiculos/disponibles')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.warnings.0', 'Mantenimiento vencido: Cambio de aceite');

        // Acción: programa e inicia mantenimiento inmovilizante luego de cerrar cualquier uso.
        Sanctum::actingAs($admin, ['api:access']);
        $maintenance = $this->postJson('/api/v1/vehiculos/'.$vehicle->public_id.'/mantenimientos', [
            'type' => 'corrective', 'description' => 'Reparación de frenos', 'immobilizes_vehicle' => true, 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->json('data.public_id');
        $this->postJson('/api/v1/vehiculos/mantenimientos/'.$maintenance.'/inicio')->assertOk();

        // Consulta: el repartidor no puede elegir un vehículo inmovilizado por intervención.
        Sanctum::actingAs($driver, ['api:access']);
        $this->getJson('/api/v1/vehiculos/disponibles')->assertOk()->assertJsonCount(0, 'data');
    }

    /** Reparto enlaza el uso y sus lecturas; el cierre libera el vehículo sin kilometraje obligatorio. */
    public function test_delivery_reserves_and_releases_vehicle_with_optional_readings(): void
    {
        // Preparación: configura repartidor, unidad productiva y vehículo.
        $this->driver();
        $vehicle = Vehicle::factory()->create();

        // Acción: inicia un reparto sin carga ni lectura de salida.
        $deliveryId = $this->postJson('/api/v1/repartos', ['pin' => '0007', 'vehicle_id' => $vehicle->public_id, 'start_without_load' => true], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('data.vehicle.plate', $vehicle->plate)->json('data.id');
        $this->getJson('/api/v1/vehiculos/disponibles')->assertOk()->assertJsonCount(0, 'data');

        // Acción: cierra el reparto con stock cero y sin capturar lectura de cierre.
        $this->postJson('/api/v1/repartos/'.$deliveryId.'/cierre', ['pin' => '0007', 'returned_quantity' => 0], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.vehicle_usage.end_odometer_km', null)->assertJsonMissingPath('data.vehicle_usage.end_engine_hours');

        // Consulta: el uso queda cerrado y el vehículo vuelve a estar disponible.
        $delivery = Delivery::query()->where('public_id', $deliveryId)->firstOrFail();
        $usage = VehicleUsage::query()->where('delivery_id', $delivery->id)->firstOrFail();
        $this->assertSame($vehicle->id, $usage->vehicle_id);
        $this->assertNotNull($usage->ended_at);
        $this->assertNull($usage->active_vehicle_key);
        $this->getJson('/api/v1/vehiculos/disponibles')->assertOk()->assertJsonCount(1, 'data');
    }

    // Flujo: registrar combustible propio no habilita cargas sin uso activo al retirar inicio de repartos.
    public function test_delivery_fuel_permission_requires_usage_even_without_delivery_start_permission(): void
    {
        // Preparación: configura un rol personalizado con combustible propio y sin inicio de repartos.
        $this->seed(IdentityPermissionSeeder::class);
        $actor = User::factory()->create();
        $actor->roles()->firstOrFail()->syncPermissions(['can_view_own_deliveries', 'can_record_delivery_fuel']);
        $vehicle = Vehicle::factory()->create();
        Sanctum::actingAs($actor);

        // Request: exige asociar el combustible al uso propio en lugar de ampliar el alcance del rol.
        $this->postJson('/api/v1/vehiculos/'.$vehicle->public_id.'/combustible', [
            'liters' => '5.000', 'fuel_type' => 'diesel', 'amount' => '100.000',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('usage_id');
        $this->assertDatabaseCount('vehicle_fuel_entries', 0);
    }
}
