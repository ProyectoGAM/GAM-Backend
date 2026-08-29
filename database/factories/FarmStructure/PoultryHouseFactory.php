<?php

namespace Database\Factories\FarmStructure;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Modules\FarmStructure\Domain\Enums\PoultryHouseStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PoultryHouse>
 */
class PoultryHouseFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'production_unit_id' => ProductionUnit::factory(),
            'name' => 'House '.fake()->unique()->numberBetween(1, 99999),
            'bird_capacity' => fake()->numberBetween(500, 50000),
            'status' => PoultryHouseStatus::Operational,
        ];
    }

    public function maintenance(): static
    {
        return $this->state(fn (): array => [
            'status' => PoultryHouseStatus::Maintenance,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'status' => PoultryHouseStatus::Inactive,
        ]);
    }
}
