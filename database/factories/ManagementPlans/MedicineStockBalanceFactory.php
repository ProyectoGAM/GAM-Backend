<?php

namespace Database\Factories\ManagementPlans;

use App\Models\ManagementPlans\MedicineStockBalance;
use App\Models\SuppliersAndCatalogs\Medicine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicineStockBalance>
 */
class MedicineStockBalanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'medicine_id' => Medicine::factory(),
            'on_hand_quantity' => 0,
            'version' => 1,
        ];
    }
}
