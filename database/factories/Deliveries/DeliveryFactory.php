<?php

namespace Database\Factories\Deliveries;

use App\Enums\Deliveries\DeliveryStatus;
use App\Models\Deliveries\Delivery;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Delivery> */
class DeliveryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'driver_id' => User::factory(),
            'production_unit_id' => ProductionUnit::factory(),
            'vehicle_reference' => 'vehicle-test',
            'status' => DeliveryStatus::Active,
            'loaded_quantity' => 100,
            'returned_quantity' => 0,
            'start_idempotency_key' => (string) Str::uuid(),
            'start_request_hash' => hash('sha256', Str::uuid()->toString()),
            'started_at' => now(),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => DeliveryStatus::Completed,
            'closed_at' => now(),
            'close_idempotency_key' => (string) Str::uuid(),
            'close_request_hash' => hash('sha256', Str::uuid()->toString()),
        ]);
    }
}
