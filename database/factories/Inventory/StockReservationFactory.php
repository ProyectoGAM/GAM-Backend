<?php

namespace Database\Factories\Inventory;

use App\Models\Inventory\StockReservation;
use App\Models\User;
use App\Modules\Inventory\Domain\Enums\StockReservationStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockReservation>
 */
class StockReservationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference_type' => null,
            'reference_id' => null,
            'status' => StockReservationStatus::Active,
            'created_by' => User::factory(),
        ];
    }
}
