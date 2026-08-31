<?php

namespace Database\Factories\Lots;

use App\Models\Lots\FlockOperation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FlockOperation> */
class FlockOperationFactory extends Factory
{
    protected $model = FlockOperation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'operation_id' => (string) Str::uuid(), 'created_by' => User::factory(),
            'idempotency_key' => (string) Str::uuid(), 'command' => 'flock.create',
            'request_hash' => hash('sha256', 'fixture'), 'result' => [],
        ];
    }
}
