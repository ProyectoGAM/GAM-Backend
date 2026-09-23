<?php

namespace Database\Factories\ManagementPlans;

use App\Models\Lots\Flock;
use App\Models\ManagementPlans\VaccinationApplication;
use App\Models\SuppliersAndCatalogs\Vaccine;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VaccinationApplication>
 */
class VaccinationApplicationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $publicId = (string) Str::ulid();
        $operationId = (string) Str::uuid();

        return [
            'public_id' => $publicId,
            'flock_id' => Flock::factory(),
            'plan_activity_id' => null,
            'plan_activity_title_snapshot' => null,
            'vaccine_id' => Vaccine::factory(),
            'product_id' => fn (array $attributes): int => (int) Vaccine::query()->findOrFail($attributes['vaccine_id'])->product_id,
            'vaccine_snapshot' => fn (array $attributes): array => ['id' => Vaccine::query()->findOrFail($attributes['vaccine_id'])->public_id],
            'product_snapshot' => fn (array $attributes): array => ['id' => Vaccine::query()->findOrFail($attributes['vaccine_id'])->product_id],
            'occurred_at' => now(),
            'responsible_user_id' => User::factory(),
            'responsible_name_snapshot' => fn (array $attributes): string => User::query()->findOrFail($attributes['responsible_user_id'])->name,
            'notes' => null,
            'inventory_quantity' => null,
            'stock_location_id' => null,
            'stock_location_name_snapshot' => null,
            'inventory_movement_id' => null,
            'created_by' => User::factory(),
            'operation_id' => $operationId,
        ];
    }
}
