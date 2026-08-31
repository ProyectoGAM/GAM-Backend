<?php

namespace Database\Factories\FarmStructure;

use App\Models\FarmStructure\Maintenance;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\User;
use App\Modules\FarmStructure\Domain\Enums\MaintenanceStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Maintenance> */
class MaintenanceFactory extends Factory
{
    protected $model = Maintenance::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'poultry_house_id' => PoultryHouse::factory(),
            'maintenance_date' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'description' => 'Reparación de instalaciones.',
            'cost_amount' => '1250.50',
            'cost_currency' => 'UYU',
            'responsible_user_id' => User::factory(),
            'responsible_name' => 'Responsable de mantenimiento',
            'created_by' => User::factory(),
            'idempotency_key' => Str::uuid()->toString(),
            'request_hash' => hash('sha256', Str::uuid()->toString()),
            'status' => MaintenanceStatus::Completed,
            'version' => 1,
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => MaintenanceStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Registro duplicado.',
            'version' => 2,
        ]);
    }
}
