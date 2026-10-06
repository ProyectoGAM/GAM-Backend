<?php

namespace Database\Factories\Inventory;

use App\Models\Inventory\EggPresentation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EggPresentation> */
final class EggPresentationFactory extends Factory
{
    protected $model = EggPresentation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'eggs_per_unit' => fake()->randomElement([1, 12, 30, 180, 360]),
            'default_unit_price' => fake()->numberBetween(1, 10000),
        ];
    }
}
