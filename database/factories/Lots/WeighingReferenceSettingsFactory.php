<?php

namespace Database\Factories\Lots;

use App\Models\Lots\WeighingReferenceSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WeighingReferenceSettings>
 */
class WeighingReferenceSettingsFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'singleton_key' => true,
            'adult_from_week' => 18,
            'chick_min_weight_g' => '10.0',
            'chick_max_weight_g' => '100.0',
            'adult_min_weight_g' => '100.0',
            'adult_max_weight_g' => '3000.0',
            'captured_unit' => 'g',
            'version' => 1,
        ];
    }
}
