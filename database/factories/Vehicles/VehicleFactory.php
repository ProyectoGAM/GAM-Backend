<?php

namespace Database\Factories\Vehicles;

use App\Models\Vehicles\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Vehicle> */
class VehicleFactory extends Factory
{
    public function definition(): array
    {
        $plate = strtoupper(fake()->unique()->bothify('??####'));
        return [
            'plate' => $plate,
            'plate_normalized' => preg_replace('/[^A-Z0-9]/', '', $plate),
            'make' => fake()->randomElement(['Fiat', 'Chevrolet', 'Volkswagen']),
            'model' => fake()->randomElement(['Fiorino', 'Saveiro', 'Kangoo']),
            'year' => fake()->numberBetween(2010, 2026),
            'status' => 'active',
            'public_id' => (string) Str::ulid(),
            'version' => 1,
        ];
    }
}
