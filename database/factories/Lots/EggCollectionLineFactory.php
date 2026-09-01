<?php

namespace Database\Factories\Lots;

use App\Models\Inventory\StockLocation;
use App\Models\Lots\EggCollection;
use App\Models\Lots\EggCollectionLine;
use App\Models\SuppliersAndCatalogs\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EggCollectionLine> */
class EggCollectionLineFactory extends Factory
{
    protected $model = EggCollectionLine::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'egg_collection_id' => EggCollection::factory(),
            'product_id' => Product::factory(),
            'stock_location_id' => StockLocation::factory(),
            'quantity' => 2,
        ];
    }
}
