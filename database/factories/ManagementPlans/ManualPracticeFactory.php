<?php

namespace Database\Factories\ManagementPlans;

use App\Models\Lots\Flock;
use App\Models\ManagementPlans\ManualPractice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ManualPractice>
 */
class ManualPracticeFactory extends Factory
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
            'practice_type' => 'other',
            'practice_title_snapshot' => 'Práctica demo',
            'occurred_at' => now(),
            'responsible_user_id' => User::factory(),
            'responsible_name_snapshot' => fn (array $attributes): string => User::query()->findOrFail($attributes['responsible_user_id'])->name,
            'notes' => 'Registro de prueba.',
            'created_by' => User::factory(),
            'operation_id' => (string) Str::uuid(),
        ];
    }
}
