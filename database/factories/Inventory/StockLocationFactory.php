<?php

namespace Database\Factories\Inventory;

use App\Enums\Inventory\StockLocationStatus;
use App\Models\Inventory\StockLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockLocation>
 */
class StockLocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'production_unit_id' => null,
            'name' => fake()->unique()->words(2, true),
            'status' => StockLocationStatus::Active,
        ];
    }
}
