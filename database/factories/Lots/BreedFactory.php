<?php

namespace Database\Factories\Lots;

use App\Models\Lots\Breed;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Breed> */
class BreedFactory extends Factory
{
    protected $model = Breed::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['name' => fake()->unique()->words(3, true), 'status' => 'active', 'version' => 1];
    }

    public function inactive(): static
    {
        return $this->state(['status' => 'inactive']);
    }
}
