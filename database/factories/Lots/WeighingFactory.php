<?php

namespace Database\Factories\Lots;

use App\Models\Lots\Flock;
use App\Models\Lots\Weighing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Weighing>
 */
class WeighingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'flock_id' => Flock::factory(),
            'poultry_house_id' => fn (array $data): int => Flock::query()->findOrFail($data['flock_id'])->poultry_house_id,
            'production_unit_id' => fn (array $data): int => Flock::query()->findOrFail($data['flock_id'])->production_unit_id,
            'mode' => 'individual',
            'occurred_at' => now()->subDay(),
            'captured_unit' => 'g',
            'notes' => null,
            'stage' => null,
            'min_weight_g' => null,
            'max_weight_g' => null,
            'reference_adult_from_week' => null,
            'reference_chick_min_weight_g' => null,
            'reference_chick_max_weight_g' => null,
            'reference_adult_min_weight_g' => null,
            'reference_adult_max_weight_g' => null,
            'reference_unit' => null,
            'reference_version' => null,
            'represented_bird_count' => 1,
            'total_weight_g' => '20.0',
            'average_weight_g' => '20.000000',
            'outside_expected_range' => false,
            'version' => 1,
            'created_by' => User::factory(),
        ];
    }
}
