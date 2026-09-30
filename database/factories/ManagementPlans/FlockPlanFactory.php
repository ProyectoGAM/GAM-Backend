<?php

namespace Database\Factories\ManagementPlans;

use App\Models\Lots\Flock;
use App\Models\ManagementPlans\FlockPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FlockPlan>
 */
class FlockPlanFactory extends Factory
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
            'flock_id' => Flock::factory(),
            'baseline_date' => today(),
            'current_revision' => 1,
            'created_by' => User::factory(),
            'operation_id' => (string) Str::uuid(),
        ];
    }
}
