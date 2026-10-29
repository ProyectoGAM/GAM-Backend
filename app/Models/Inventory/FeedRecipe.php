<?php

namespace App\Models\Inventory;

use App\Enums\Inventory\FeedRecipeStatus;
use Database\Factories\Inventory\FeedRecipeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $notes
 * @property int $yield_grams
 * @property FeedRecipeStatus $status
 * @property int $created_by
 * @property string $operation_id
 * @property string $idempotency_key
 * @property string $request_hash
 */
class FeedRecipe extends Model
{
    /** @use HasFactory<FeedRecipeFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['yield_grams' => 'integer', 'created_by' => 'integer', 'status' => FeedRecipeStatus::class];
    }

    /** @return HasMany<FeedRecipeIngredient, $this> */
    public function ingredients(): HasMany
    {
        return $this->hasMany(FeedRecipeIngredient::class)->orderBy('product_id');
    }
}
