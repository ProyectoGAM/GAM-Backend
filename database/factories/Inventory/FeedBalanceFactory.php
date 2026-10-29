<?php

namespace Database\Factories\Inventory;

use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\FeedBalance;
use App\Models\Inventory\FeedRecipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FeedBalance> */
class FeedBalanceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['feed_recipe_id' => FeedRecipe::factory(), 'production_unit_id' => ProductionUnit::factory(), 'quantity_grams' => 0];
    }
}
