<?php

namespace Database\Factories\ManagementPlans;

use App\Models\Lots\Flock;
use App\Models\ManagementPlans\ManagementExecutionCorrection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ManagementExecutionCorrection>
 */
class ManagementExecutionCorrectionFactory extends Factory
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
            'original_type' => 'manual_practice',
            'original_public_id' => (string) fake()->ulid(),
            'original_operation_id' => (string) fake()->uuid(),
            'correction_reason' => fake()->sentence(),
            'original_snapshot' => ['title' => 'Despique'],
            'compensation_snapshot' => null,
            'occurred_at' => now(),
            'created_by' => User::factory(),
            'operation_id' => (string) fake()->uuid(),
        ];
    }
}
