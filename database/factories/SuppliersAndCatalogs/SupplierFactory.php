<?php

namespace Database\Factories\SuppliersAndCatalogs;

use App\Models\Geography\Locality;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\SupplierStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'locality_id' => Locality::factory(),
            'name' => fake()->unique()->company(),
            'address' => fake()->streetAddress(),
            'status' => SupplierStatus::Active,
        ];
    }
}
