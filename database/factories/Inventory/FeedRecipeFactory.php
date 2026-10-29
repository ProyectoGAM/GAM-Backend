<?php

namespace Database\Factories\Inventory;

use App\Enums\Inventory\FeedRecipeStatus;
use App\Models\Inventory\FeedRecipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FeedRecipe> */
class FeedRecipeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true), 'notes' => null, 'yield_grams' => 13000,
            'status' => FeedRecipeStatus::Active, 'created_by' => User::factory(),
            'operation_id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(),
            'request_hash' => hash('sha256', (string) Str::uuid()),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => FeedRecipeStatus::Inactive]);
    }
}
