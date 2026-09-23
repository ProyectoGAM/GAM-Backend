<?php

namespace Database\Factories\ManagementPlans;

use App\Models\ManagementPlans\FlockPlanActivity;
use App\Models\ManagementPlans\FlockPlanRevision;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FlockPlanActivity>
 */
class FlockPlanActivityFactory extends Factory
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
            'flock_plan_revision_id' => FlockPlanRevision::factory(),
            'sort_order' => 1,
            'type' => 'weighing',
            'title' => 'Pesaje semanal',
            'timing_kind' => 'week',
            'start_week' => 1,
            'conditional' => false,
        ];
    }
}
