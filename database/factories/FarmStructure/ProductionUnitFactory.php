<?php

namespace Database\Factories\FarmStructure;

use App\Models\FarmStructure\ProductionUnit;
use App\Models\Geography\Locality;
use App\Modules\FarmStructure\Domain\Enums\ProductionUnitStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionUnit>
 */
class ProductionUnitFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'locality_id' => Locality::factory(),
            'name' => 'Farm '.fake()->unique()->company(),
            'latitude' => fake()->latitude(-35.0, -30.0),
            'longitude' => fake()->longitude(-58.5, -53.0),
            'status' => ProductionUnitStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'status' => ProductionUnitStatus::Inactive,
        ]);
    }
}
