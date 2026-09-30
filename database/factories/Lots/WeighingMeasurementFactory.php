<?php

namespace Database\Factories\Lots;

use App\Models\Lots\Weighing;
use App\Models\Lots\WeighingMeasurement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WeighingMeasurement>
 */
class WeighingMeasurementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'weighing_id' => Weighing::factory(),
            'position' => 1,
            'weight_g' => '20.0',
            'total_weight_g' => null,
            'bird_count' => null,
            'average_weight_g' => null,
            'outside_expected_range' => false,
        ];
    }
}
