<?php

namespace Database\Factories\Inventory;

use App\Models\Inventory\StockLocation;
use App\Models\Inventory\StockReservation;
use App\Models\Inventory\StockReservationLine;
use App\Models\SuppliersAndCatalogs\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockReservationLine>
 */
class StockReservationLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stock_reservation_id' => StockReservation::factory(),
            'product_id' => Product::factory(),
            'stock_location_id' => StockLocation::factory(),
            'unit' => 'kg',
            'reserved_quantity' => '1.000000',
            'released_quantity' => '0.000000',
            'consumed_quantity' => '0.000000',
        ];
    }
}
