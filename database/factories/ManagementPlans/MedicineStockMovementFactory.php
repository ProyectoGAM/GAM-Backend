<?php

namespace Database\Factories\ManagementPlans;

use App\Models\ManagementPlans\MedicineStockMovement;
use App\Models\SuppliersAndCatalogs\Medicine;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MedicineStockMovement>
 */
class MedicineStockMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'medicine_id' => Medicine::factory(),
            'medicine_public_id_snapshot' => fn (array $attributes): string => Medicine::query()->findOrFail($attributes['medicine_id'])->public_id,
            'medicine_name_snapshot' => fn (array $attributes): string => Medicine::query()->findOrFail($attributes['medicine_id'])->name,
            'medicine_application_public_id' => null,
            'movement_type' => 'adjustment',
            'quantity_delta' => 1,
            'balance_after' => 1,
            'reason' => fake()->sentence(),
            'operation_id' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'request_hash' => hash('sha256', (string) Str::uuid()),
            'created_by' => User::factory(),
        ];
    }
}
