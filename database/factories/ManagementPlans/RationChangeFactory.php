<?php

namespace Database\Factories\ManagementPlans;

use App\Models\Lots\Flock;
use App\Models\ManagementPlans\RationChange;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RationChange>
 */
class RationChangeFactory extends Factory
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
            'plan_activity_id' => null,
            'plan_activity_title_snapshot' => null,
            'ration_description' => fake()->sentence(),
            'finished_feed_product_id' => null,
            'finished_feed_product_snapshot' => null,
            'occurred_at' => now(),
            'responsible_user_id' => User::factory(),
            'responsible_name_snapshot' => fn (array $attributes): string => User::query()->findOrFail($attributes['responsible_user_id'])->name,
            'notes' => null,
            'created_by' => User::factory(),
            'operation_id' => (string) Str::uuid(),
        ];
    }
}
