<?php

namespace Database\Factories\ManagementPlans;

use App\Models\Lots\Flock;
use App\Models\ManagementPlans\MedicineApplication;
use App\Models\ManagementPlans\MedicineStockMovement;
use App\Models\SuppliersAndCatalogs\Medicine;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MedicineApplication>
 */
class MedicineApplicationFactory extends Factory
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
        $createdBy = User::factory();

        return [
            'public_id' => $publicId,
            'flock_id' => Flock::factory(),
            'plan_activity_id' => null,
            'plan_activity_title_snapshot' => null,
            'medicine_id' => Medicine::factory(),
            'medicine_public_id_snapshot' => fn (array $attributes): string => Medicine::query()->findOrFail($attributes['medicine_id'])->public_id,
            'medicine_name_snapshot' => fn (array $attributes): string => Medicine::query()->findOrFail($attributes['medicine_id'])->name,
            'medicine_description_snapshot' => fn (array $attributes): string => Medicine::query()->findOrFail($attributes['medicine_id'])->description,
            'supplier_name_snapshot' => fn (array $attributes): string => Medicine::query()->findOrFail($attributes['medicine_id'])->supplier_name_snapshot,
            'occurred_at' => now(),
            'responsible_user_id' => User::factory(),
            'responsible_name_snapshot' => fn (array $attributes): string => User::query()->findOrFail($attributes['responsible_user_id'])->name,
            'quantity' => 1,
            'reason' => 'Tratamiento de prueba.',
            'notes' => null,
            'stock_movement_id' => fn (array $attributes): int => MedicineStockMovement::factory()->create([
                'medicine_id' => $attributes['medicine_id'],
                'medicine_application_public_id' => $attributes['public_id'],
                'movement_type' => 'application',
                'quantity_delta' => -$attributes['quantity'],
                'balance_after' => -$attributes['quantity'],
                'operation_id' => $operationId,
                'created_by' => $attributes['created_by'] ?? $createdBy,
            ])->getKey(),
            'created_by' => $createdBy,
            'operation_id' => $operationId,
        ];
    }
}
