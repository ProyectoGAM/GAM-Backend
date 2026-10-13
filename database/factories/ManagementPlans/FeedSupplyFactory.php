<?php

namespace Database\Factories\ManagementPlans;

use App\Models\Lots\Flock;
use App\Models\ManagementPlans\FeedSupply;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FeedSupply> */
class FeedSupplyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(), 'flock_id' => Flock::factory(),
            'poultry_house_id' => fn (array $attributes) => Flock::findOrFail($attributes['flock_id'])->poultry_house_id,
            'production_unit_id' => fn (array $attributes) => Flock::findOrFail($attributes['flock_id'])->production_unit_id,
            'product_id' => Product::factory(), 'product_name_snapshot' => 'Ración de postura',
            'quantity' => '25.000000', 'unit' => 'kg', 'occurred_at' => now(), 'status' => 'recorded',
            'operation_id' => (string) Str::uuid(), 'created_by' => User::factory(),
        ];
    }
}
