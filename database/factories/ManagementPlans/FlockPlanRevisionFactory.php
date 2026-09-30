<?php

namespace Database\Factories\ManagementPlans;

use App\Models\ManagementPlans\FlockPlan;
use App\Models\ManagementPlans\FlockPlanRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FlockPlanRevision>
 */
class FlockPlanRevisionFactory extends Factory
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
            'flock_plan_id' => FlockPlan::factory(),
            'number' => 1,
            'operation_id' => (string) Str::uuid(),
            'reason' => 'Asignación inicial.',
            'created_by' => User::factory(),
        ];
    }
}
