<?php

namespace Database\Factories\Geography;

use App\Models\Geography\Department;
use App\Models\Geography\Locality;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Locality>
 */
class LocalityFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'name' => fake()->unique()->city(),
        ];
    }
}
