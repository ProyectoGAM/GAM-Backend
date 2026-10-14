<?php

namespace App\Actions\Inventory;

use App\Enums\Inventory\FeedRecipeStatus;
use App\Exceptions\Inventory\FeedRecipeConflict;
use App\Models\Inventory\FeedRecipe;
use App\Models\Inventory\FeedRecipeIngredient;
use App\Models\User;
use App\Services\Inventory\FeedRecipeCalculator;
use App\ValueObjects\Inventory\RecipeGrams;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class CalculateFeedRecipeAction
{
    public function __construct(private FeedRecipeCalculator $calculator) {}

    /** @return array<string, mixed> */
    public function execute(FeedRecipe $recipe, mixed $requestedGrams, User $actor): array
    {
        Gate::forUser($actor)->authorize('calculate', $recipe);

        return DB::transaction(function () use ($recipe, $requestedGrams, $actor): array {
            $current = FeedRecipe::query()->whereKey($recipe->getKey())->sharedLock()->firstOrFail();
            Gate::forUser($actor)->authorize('calculate', $current);
            if ($current->status !== FeedRecipeStatus::Active) {
                throw new FeedRecipeConflict('La receta está desactivada y no puede utilizarse para una nueva elaboración.');
            }
            $current->load('ingredients.product');
            try {
                $requested = RecipeGrams::from($requestedGrams)->value;
                $calculation = $this->calculator->calculate($current->yield_grams, $requested, $current->ingredients->pluck('quantity_grams', 'product_id')->all());
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['cantidad_g' => $exception->getMessage()]);
            }

            return [
                'recipe' => $current,
                'requested_grams' => $requested,
                'factor' => $calculation['factor'],
                'ingredients' => $current->ingredients->map(fn (FeedRecipeIngredient $ingredient): array => [
                    'product_id' => $ingredient->product_id, 'name' => $ingredient->product->name,
                    'base_grams' => $ingredient->quantity_grams, 'required_grams' => $calculation['quantities'][$ingredient->product_id],
                ])->all(),
            ];
        }, 3);
    }
}
