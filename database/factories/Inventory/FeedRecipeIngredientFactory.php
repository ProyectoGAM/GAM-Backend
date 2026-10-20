<?php

namespace Database\Factories\Inventory;

use App\Models\Inventory\FeedRecipe;
use App\Models\Inventory\FeedRecipeIngredient;
use App\Models\SuppliersAndCatalogs\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FeedRecipeIngredient> */
class FeedRecipeIngredientFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'feed_recipe_id' => FeedRecipe::factory(),
            'product_id' => Product::factory()->rawMaterial(), 'quantity_grams' => 2000,
        ];
    }
}
