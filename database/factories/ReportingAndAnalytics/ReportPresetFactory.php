<?php

namespace Database\Factories\ReportingAndAnalytics;

use App\Models\ReportingAndAnalytics\ReportPreset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportPreset>
 */
final class ReportPresetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->sentence(3),
            'normalized_name' => fake()->unique()->slug(3),
            'source_key' => 'inventory.stock-balances',
            'definition_version' => '1.0',
            'configuration' => [
                'columns' => ['product', 'base_unit', 'available_quantity'],
                'filters' => [],
                'from' => null,
                'to' => null,
                'sorts' => [['field' => 'product', 'direction' => 'asc']],
                'groupings' => [],
                'metrics' => [],
                'page' => 1,
                'per_page' => 50,
            ],
        ];
    }
}
