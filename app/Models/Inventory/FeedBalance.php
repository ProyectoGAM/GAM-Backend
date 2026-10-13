<?php

namespace App\Models\Inventory;

use App\Models\FarmStructure\ProductionUnit;
use Database\Factories\Inventory\FeedBalanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $quantity_grams */
class FeedBalance extends Model
{
    /** @use HasFactory<FeedBalanceFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity_grams' => 'integer'];
    }

    /** @return BelongsTo<FeedRecipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(FeedRecipe::class, 'feed_recipe_id');
    }

    /** @return BelongsTo<ProductionUnit, $this> */
    public function productionUnit(): BelongsTo
    {
        return $this->belongsTo(ProductionUnit::class);
    }
}
