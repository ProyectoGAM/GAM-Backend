<?php

namespace Database\Factories\Inventory;

use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\FeedRecipe;
use App\Models\Inventory\FeedStockOperation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FeedStockOperation> */
class FeedStockOperationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'kind' => 'feed_adjustment', 'operation_id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(),
            'request_hash' => hash('sha256', (string) Str::uuid()), 'created_by' => User::factory(),
            'production_unit_id' => ProductionUnit::factory(), 'feed_recipe_id' => FeedRecipe::factory(),
            'quantity_grams' => 10, 'adjustment_mode' => 'sumar', 'reason' => 'Recuento de prueba',
            'balance_before' => '0', 'balance_after' => '10', 'delta_grams' => '10',
            'snapshot' => [], 'occurred_at' => now(),
        ];
    }
}
