<?php

namespace Database\Factories\Inventory;

use App\Models\Inventory\InventoryMovement;
use App\Models\User;
use App\Modules\Inventory\Domain\Enums\InventoryMovementType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InventoryMovement>
 */
class InventoryMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'operation_id' => (string) Str::uuid(),
            'request_hash' => hash('sha256', (string) Str::uuid()),
            'type' => InventoryMovementType::Receipt,
            'supplier_id' => null,
            'reference_type' => null,
            'reference_id' => null,
            'reason' => fake()->sentence(),
            'occurred_at' => now(),
            'created_by' => User::factory(),
        ];
    }
}
