<?php

namespace Database\Seeders\Vehicles;

use App\Actions\IdentityAndAccess\SetUserPinAction;
use App\Models\User;
use App\Models\Vehicles\Vehicle;
use App\Models\Vehicles\VehicleFuelEntry;
use App\Models\Vehicles\VehicleMaintenance;
use App\Models\Vehicles\VehicleMaintenanceItem;
use App\Models\Vehicles\VehicleMaintenancePlan;
use App\Models\Vehicles\VehicleMeterReading;
use App\Models\Vehicles\VehicleUsage;
use DateTimeInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

final class VehiclesDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $admin = User::query()->role('admin')->orderBy('id')->firstOrFail();
        $driver = $this->seedDriver($admin);
        $van = $this->seedVehicle($admin, [
            'plate' => 'SJD-2040', 'make' => 'Ford', 'model' => 'Transit', 'year' => 2023,
            'capacity_kg' => '1000.00', 'status' => 'active', 'status_reason' => null,
            'habitual_driver_id' => $driver->getKey(), 'notes' => 'Vehículo demo disponible para iniciar repartos.',
        ]);
        $utility = $this->seedVehicle($admin, [
            'plate' => 'SJD-7830', 'make' => 'Fiat', 'model' => 'Fiorino', 'year' => 2019,
            'capacity_kg' => '650.00', 'status' => 'out_of_service', 'status_reason' => 'Demostración: pendiente de reparación de frenos.',
            'habitual_driver_id' => null, 'notes' => 'Ejemplo de vehículo fuera de servicio.',
        ]);
        $pickup = $this->seedVehicle($admin, [
            'plate' => 'SJD-5512', 'make' => 'Chevrolet', 'model' => 'Montana', 'year' => 2021,
            'capacity_kg' => '720.00', 'status' => 'active', 'status_reason' => null,
            'habitual_driver_id' => $driver->getKey(), 'notes' => 'Ejemplo de vehículo inmovilizado por mantenimiento.',
        ]);

        $vanUsage = $this->seedClosedUsage($van, $driver, 'local-demo-vehicle-usage-van-001', 'Recorrido de demostración', '48200.0', '48340.0', 4);
        $this->seedReading($van, $vanUsage, $admin, 'local-demo-vehicle-reading-van-start', '48200.0', $vanUsage->started_at, 'usage_start', 'Lectura de salida demo.');
        $this->seedReading($van, $vanUsage, $admin, 'local-demo-vehicle-reading-van-end', '48340.0', $vanUsage->ended_at, 'usage_end', 'Lectura de cierre demo.');
        $this->seedReading($van, null, $admin, 'local-demo-vehicle-reading-van-current', '48500.0', now()->subHours(2), 'manual', 'Lectura manual demo.');
        $oilPlan = $this->seedPlan($van, 'Cambio de aceite', [
            'interval_days' => 90, 'interval_km' => '5000.0',
            'last_completed_at' => now()->subDays(70)->toDateString(), 'last_odometer_km' => '43700.0',
            'next_due_at' => now()->addDays(20)->toDateString(),
            'next_due_odometer_km' => '48700.0', 'next_due_odometer_epoch' => 0,
        ]);
        $this->seedFuel($van, $vanUsage, $driver, 'local-demo-vehicle-fuel-van-001', '40.000', '2850.000', 'Nafta', now()->subDays(4), 'Estación de la ruta', '48250.0');
        $this->seedCompletedMaintenance($van, $admin, $oilPlan);

        $this->seedReading($utility, null, $admin, 'local-demo-vehicle-reading-utility-current', '94320.0', now()->subDays(1), 'manual', 'Lectura manual demo.');
        $this->seedPlan($utility, 'Revisión de frenos', [
            'interval_days' => 180, 'interval_km' => '10000.0',
            'last_completed_at' => now()->subDays(220)->toDateString(), 'last_odometer_km' => '84000.0',
            'next_due_at' => now()->subDays(40)->toDateString(),
            'next_due_odometer_km' => '94000.0', 'next_due_odometer_epoch' => 0,
        ]);
        $this->seedFuel($utility, null, $admin, 'local-demo-vehicle-fuel-utility-001', '32.500', '2460.000', 'Nafta', now()->subDays(25), 'Estación local', '93800.0');

        $this->seedReading($pickup, null, $admin, 'local-demo-vehicle-reading-pickup-current', '32150.0', now()->subHours(5), 'manual', 'Lectura manual demo.');
        $this->seedPlan($pickup, 'Service general', [
            'interval_days' => 120, 'interval_km' => '7000.0',
            'last_completed_at' => now()->subDays(35)->toDateString(), 'last_odometer_km' => '30000.0',
            'next_due_at' => now()->addDays(85)->toDateString(),
            'next_due_odometer_km' => '37000.0', 'next_due_odometer_epoch' => 0,
        ]);
        $this->seedInProgressMaintenance($pickup, $admin);
    }

    private function seedDriver(User $admin): User
    {
        $email = (string) config('auth.demo_delivery.email');
        $driver = User::withTrashed()->firstOrNew(['email' => $email]);
        $isNew = ! $driver->exists;

        if ($driver->trashed()) {
            $driver->restore();
        }

        if ($isNew) {
            $driver->forceFill([
                'name' => 'Repartidor Demo',
                'email' => $email,
                'password' => (string) config('auth.demo_delivery.password'),
                'email_verified_at' => now(),
            ])->save();
        }

        $driver->syncRoles([Role::findByName('delivery', 'web')]);
        if (! $driver->pin_enabled) {
            app(SetUserPinAction::class)->execute($admin, $driver, (string) config('auth.demo_delivery.pin'));
        }

        return $driver;
    }

    /** @param array<string, mixed> $attributes */
    private function seedVehicle(User $admin, array $attributes): Vehicle
    {
        $plate = (string) $attributes['plate'];
        $normalized = preg_replace('/[^A-Z0-9]/', '', Str::upper($plate)) ?? '';
        $vehicle = Vehicle::query()->firstOrNew(['plate_normalized' => $normalized]);
        $created = ! $vehicle->exists;
        $previousStatus = $vehicle->status;
        $vehicle->forceFill([
            'public_id' => $vehicle->public_id ?? (string) Str::ulid(),
            'plate' => $plate,
            'plate_normalized' => $normalized,
            'make' => $attributes['make'],
            'model' => $attributes['model'],
            'year' => $attributes['year'],
            'admitted_at' => now()->subYears(2)->toDateString(),
            'status' => $attributes['status'],
            'status_reason' => $attributes['status_reason'],
            'capacity_kg' => $attributes['capacity_kg'],
            'habitual_driver_id' => $attributes['habitual_driver_id'],
            'notes' => $attributes['notes'],
            'version' => 1,
        ])->save();

        if ($created || $previousStatus !== $vehicle->status) {
            $change = $vehicle->statusChanges()->make([
                'actor_id' => $admin->getKey(),
                'from_status' => $created ? null : $previousStatus,
                'to_status' => $vehicle->status,
                'reason' => $vehicle->status_reason ?? 'Alta inicial del vehículo de demostración.',
                'occurred_at' => now(),
            ]);
            $change->forceFill(['public_id' => (string) Str::ulid()])->save();
        }

        return $vehicle;
    }

    private function seedClosedUsage(Vehicle $vehicle, User $driver, string $key, string $purpose, string $startKm, string $endKm, int $daysAgo): VehicleUsage
    {
        $startedAt = now()->subDays($daysAgo)->setTime(8, 30);
        $endedAt = $startedAt->copy()->setTime(12, 15);
        $usage = VehicleUsage::query()->firstOrNew(['idempotency_key' => $key]);
        $usage->forceFill([
            'public_id' => $usage->public_id ?? (string) Str::ulid(),
            'vehicle_id' => $vehicle->getKey(), 'driver_id' => $driver->getKey(), 'delivery_id' => null,
            'active_vehicle_key' => null, 'purpose' => $purpose, 'notes' => 'Uso histórico ficticio para desarrollo.',
            'started_at' => $startedAt, 'ended_at' => $endedAt, 'start_odometer_km' => $startKm,
            'end_odometer_km' => $endKm,
            'vehicle_snapshot' => $vehicle->snapshot(), 'driver_snapshot' => $driver->name,
        ])->save();

        return $usage;
    }

    private function seedReading(Vehicle $vehicle, ?VehicleUsage $usage, User $actor, string $key, string $value, DateTimeInterface $recordedAt, string $source, string $notes): void
    {
        $reading = VehicleMeterReading::query()->firstOrNew(['idempotency_key' => $key]);
        $reading->forceFill([
            'public_id' => $reading->public_id ?? (string) Str::ulid(),
            'vehicle_id' => $vehicle->getKey(), 'usage_id' => $usage?->getKey(), 'created_by' => $actor->getKey(),
            'corrects_reading_id' => null, 'meter_type' => 'odometer', 'event_type' => 'reading',
            'event_reason' => null, 'counter_epoch' => 0, 'value' => $value, 'recorded_at' => $recordedAt,
            'received_at' => $recordedAt, 'source' => $source, 'correction_reason' => null, 'notes' => $notes,
        ])->save();
    }

    /** @param array<string, mixed> $attributes */
    private function seedPlan(Vehicle $vehicle, string $task, array $attributes): VehicleMaintenancePlan
    {
        $plan = VehicleMaintenancePlan::query()->firstOrNew(['vehicle_id' => $vehicle->getKey(), 'task' => $task]);
        $plan->forceFill([
            ...$attributes,
            'public_id' => $plan->public_id ?? (string) Str::ulid(),
            'vehicle_id' => $vehicle->getKey(), 'task' => $task, 'active' => true,
            'version' => 1,
        ])->save();

        return $plan;
    }

    private function seedFuel(Vehicle $vehicle, ?VehicleUsage $usage, User $actor, string $key, string $liters, string $amount, string $fuelType, DateTimeInterface $occurredAt, string $supplier, string $odometer): void
    {
        $entry = VehicleFuelEntry::query()->firstOrNew(['idempotency_key' => $key]);
        $entry->forceFill([
            'public_id' => $entry->public_id ?? (string) Str::ulid(),
            'vehicle_id' => $vehicle->getKey(), 'usage_id' => $usage?->getKey(), 'created_by' => $actor->getKey(),
            'occurred_at' => $occurredAt, 'liters' => $liters, 'fuel_type' => $fuelType,
            'amount' => $amount, 'currency' => 'UYU', 'supplier' => $supplier, 'odometer_km' => $odometer,
            'receipt_path' => null, 'receipt_sha256' => null, 'receipt_original_name' => null,
            'notes' => 'Carga ficticia para el reporte de desarrollo.',
        ])->save();
    }

    private function seedCompletedMaintenance(Vehicle $vehicle, User $admin, VehicleMaintenancePlan $plan): void
    {
        $key = 'local-demo-maintenance-van-oil-completed';
        $maintenance = VehicleMaintenance::query()->firstOrNew(['idempotency_key' => $key]);
        $maintenance->forceFill([
            'public_id' => $maintenance->public_id ?? (string) Str::ulid(),
            'vehicle_id' => $vehicle->getKey(), 'created_by' => $admin->getKey(), 'completed_by' => $admin->getKey(),
            'type' => 'preventive', 'status' => 'completed', 'immobilizes_vehicle' => false,
            'workshop' => 'Taller San José', 'description' => 'Cambio de aceite y filtro',
            'notes' => 'Intervención ficticia completada.', 'planned_at' => now()->subDays(70),
            'started_at' => now()->subDays(70), 'completed_at' => now()->subDays(70)->addHours(2),
            'odometer_km' => '43700.0', 'cost_amount' => '3850.000',
            'cost_currency' => 'UYU', 'request_hash' => hash('sha256', $key),
            'cancellation_reason' => null, 'version' => 3,
        ])->save();

        $item = VehicleMaintenanceItem::query()->firstOrNew([
            'maintenance_id' => $maintenance->getKey(), 'plan_id' => $plan->getKey(),
        ]);
        $item->forceFill(['public_id' => $item->public_id ?? (string) Str::ulid(), 'maintenance_id' => $maintenance->getKey(), 'plan_id' => $plan->getKey(), 'task' => $plan->task, 'completed' => true, 'notes' => 'Realizado en taller.'])->save();
    }

    private function seedInProgressMaintenance(Vehicle $vehicle, User $admin): void
    {
        $key = 'local-demo-maintenance-pickup-corrective';
        $maintenance = VehicleMaintenance::query()->firstOrNew(['idempotency_key' => $key]);
        $maintenance->forceFill([
            'public_id' => $maintenance->public_id ?? (string) Str::ulid(),
            'vehicle_id' => $vehicle->getKey(), 'created_by' => $admin->getKey(), 'completed_by' => null,
            'type' => 'corrective', 'status' => 'in_progress', 'immobilizes_vehicle' => true,
            'workshop' => 'Taller de la flota', 'description' => 'Reparación de tren delantero',
            'notes' => 'Intervención ficticia en curso; el vehículo no está disponible.',
            'planned_at' => now()->subDay(), 'started_at' => now()->subHours(3), 'completed_at' => null,
            'odometer_km' => '32150.0', 'cost_amount' => '0.000',
            'cost_currency' => 'UYU', 'request_hash' => hash('sha256', $key),
            'cancellation_reason' => null, 'version' => 2,
        ])->save();
    }
}
