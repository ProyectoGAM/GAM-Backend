<?php

namespace Database\Factories\Inventory;

use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockBalance>
 */
class StockBalanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'stock_location_id' => StockLocation::factory(),
            'on_hand_quantity' => '0.000000',
            'reserved_quantity' => '0.000000',
            'minimum_quantity' => '0.000000',
        ];
    }
}
