<?php

namespace Database\Factories\Lots;

use App\Models\Inventory\StockLocation;
use App\Models\Lots\EggCollection;
use App\Models\Lots\Flock;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<EggCollection> */
class EggCollectionFactory extends Factory
{
    protected $model = EggCollection::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(), 'flock_id' => Flock::factory(),
            'poultry_house_id' => fn (array $data): int => Flock::query()->findOrFail($data['flock_id'])->poultry_house_id,
            'production_unit_id' => fn (array $data): int => Flock::query()->findOrFail($data['flock_id'])->production_unit_id,
            'product_id' => Product::factory(), 'stock_location_id' => StockLocation::factory(),
            'inventory_movement_id' => null,
            'collected_quantity' => 2, 'quantity' => 2, 'discarded_quantity' => 0,
            'occurred_at' => now()->startOfSecond(), 'status' => 'recorded', 'version' => 1, 'created_by' => User::factory(),
        ];
    }
}
