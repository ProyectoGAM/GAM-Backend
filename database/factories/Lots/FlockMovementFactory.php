<?php

namespace Database\Factories\Lots;

use App\Models\Lots\Flock;
use App\Models\Lots\FlockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FlockMovement> */
class FlockMovementFactory extends Factory
{
    protected $model = FlockMovement::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(), 'operation_id' => (string) Str::uuid(),
            'type' => 'admission', 'destination_flock_id' => Flock::factory(),
            'quantity' => 100, 'before' => [], 'after' => [], 'occurred_at' => now()->startOfSecond(),
            'created_by' => User::factory(),
        ];
    }
}
