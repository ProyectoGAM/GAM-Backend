<?php

namespace Database\Factories\ManagementPlans;

use App\Models\ManagementPlans\PlanTemplate;
use App\Models\ManagementPlans\PlanTemplateVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlanTemplateVersion>
 */
class PlanTemplateVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_template_id' => PlanTemplate::factory(),
            'number' => 1,
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'status' => 'draft',
            'operation_id' => (string) Str::uuid(),
            'created_by' => User::factory(),
        ];
    }
}
