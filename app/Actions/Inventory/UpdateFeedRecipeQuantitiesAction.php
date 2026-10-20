<?php

namespace App\Actions\Inventory;

use App\Exceptions\Inventory\FeedRecipeConflict;
use App\Models\Inventory\FeedRecipe;
use App\Models\User;
use App\Services\Inventory\FeedRecipeHistory;
use App\ValueObjects\Inventory\RecipeGrams;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class UpdateFeedRecipeQuantitiesAction
{
    public function __construct(private FeedRecipeHistory $history) {}

    /** @param array{ingredients?: array<array-key, array{product_id: int, quantity_grams: int|string}>, ...} $attributes */
    public function execute(FeedRecipe $recipe, array $attributes, User $actor, string $source = 'api'): FeedRecipe
    {
        Gate::forUser($actor)->authorize('update', $recipe);
        if (array_keys($attributes) !== ['ingredients'] || $attributes['ingredients'] === []
            || count($attributes['ingredients']) > 1000 || ! array_is_list($attributes['ingredients'])) {
            throw new FeedRecipeConflict('Sólo puedes editar las cantidades de los ingredientes existentes.');
        }
        $quantities = [];
        try {
            foreach ($attributes['ingredients'] as $ingredient) {
                if (array_diff(array_keys($ingredient), ['product_id', 'quantity_grams']) !== []) {
                    throw new InvalidArgumentException('Sólo puedes editar las cantidades de los ingredientes existentes.');
                }
                $productId = RecipeGrams::from($ingredient['product_id'])->value;
                if (isset($quantities[$productId])) {
                    throw new InvalidArgumentException('Cada ingrediente debe aparecer una sola vez.');
                }
                $quantities[$productId] = RecipeGrams::from($ingredient['quantity_grams'])->value;
            }
        } catch (InvalidArgumentException $exception) {
            throw new FeedRecipeConflict($exception->getMessage(), previous: $exception);
        }
        ksort($quantities);

        return DB::transaction(function () use ($recipe, $quantities, $actor, $source): FeedRecipe {
            $locked = FeedRecipe::query()->whereKey($recipe->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $locked);
            $locked->load('ingredients.product');
            if ($locked->ingredients->pluck('product_id')->all() !== array_keys($quantities)) {
                throw new FeedRecipeConflict('Debes conservar todos los ingredientes originales, sin agregar, eliminar ni sustituir ninguno.');
            }
            $before = $this->history->snapshot($locked);
            $changed = false;
            foreach ($locked->ingredients as $ingredient) {
                if ($ingredient->quantity_grams !== $quantities[$ingredient->product_id]) {
                    $ingredient->forceFill(['quantity_grams' => $quantities[$ingredient->product_id]])->save();
                    $changed = true;
                }
            }
            if ($changed) {
                $locked->touch();
                $this->history->record($locked, $actor, 'feed_recipe_quantities_updated', 'Cantidades de receta actualizadas', $before, (string) Str::uuid(), $source);
            }

            return $locked;
        }, 3);
    }
}
