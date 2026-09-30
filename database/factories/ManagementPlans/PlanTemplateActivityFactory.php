<?php

namespace Database\Factories\ManagementPlans;

use App\Models\ManagementPlans\PlanTemplateActivity;
use App\Models\ManagementPlans\PlanTemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlanTemplateActivity>
 */
class PlanTemplateActivityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'plan_template_version_id' => PlanTemplateVersion::factory(),
            'sort_order' => 1,
            'type' => 'weighing',
            'title' => 'Pesaje semanal',
            'timing_kind' => 'week',
            'start_week' => 1,
            'conditional' => false,
        ];
    }
}
