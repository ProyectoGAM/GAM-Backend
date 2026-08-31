<?php

namespace Database\Factories\Inventory;

use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\InventoryMovementLine;
use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryMovementLine>
 */
class InventoryMovementLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inventory_movement_id' => InventoryMovement::factory(),
            'product_id' => Product::factory(),
            'stock_location_id' => StockLocation::factory(),
            'unit' => 'kg',
            'on_hand_delta' => '1.000000',
        ];
    }
}
