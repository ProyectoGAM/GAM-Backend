<?php

namespace App\Models\Inventory;

use App\Models\SuppliersAndCatalogs\Product;
use Database\Factories\Inventory\FeedRecipeIngredientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $feed_recipe_id
 * @property int $product_id
 * @property int $quantity_grams
 */
class FeedRecipeIngredient extends Model
{
    /** @use HasFactory<FeedRecipeIngredientFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['feed_recipe_id' => 'integer', 'product_id' => 'integer', 'quantity_grams' => 'integer'];
    }

    /** @return BelongsTo<FeedRecipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(FeedRecipe::class, 'feed_recipe_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
