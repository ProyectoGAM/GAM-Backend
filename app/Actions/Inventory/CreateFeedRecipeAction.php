<?php

namespace App\Actions\Inventory;

use App\Enums\Inventory\FeedRecipeStatus;
use App\Enums\SuppliersAndCatalogs\BaseUnit;
use App\Enums\SuppliersAndCatalogs\ProductKind;
use App\Exceptions\Inventory\FeedRecipeConflict;
use App\Models\Inventory\FeedRecipe;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use App\Services\Inventory\FeedRecipeHistory;
use App\ValueObjects\Inventory\RecipeGrams;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class CreateFeedRecipeAction
{
    public function __construct(private FeedRecipeHistory $history) {}

    /** @param array{name: string, yield_grams: int|string, notes?: string|null, ingredients: array<array-key, array{product_id: int, quantity_grams: int|string}>, idempotency_key: string} $attributes */
    public function execute(array $attributes, User $actor, string $source = 'api'): FeedRecipe
    {
        Gate::forUser($actor)->authorize('create', FeedRecipe::class);
        $name = trim($attributes['name']);
        $notes = $attributes['notes'] ?? null;
        if ($name === '' || mb_strlen($name) > 160 || ($notes !== null && mb_strlen($notes) > 5000)
            || ! Str::isUuid($attributes['idempotency_key'])
            || array_diff(array_keys($attributes), ['name', 'yield_grams', 'notes', 'ingredients', 'idempotency_key']) !== []) {
            throw new FeedRecipeConflict('El nombre, la nota o la clave de idempotencia de la receta no son válidos.');
        }
        try {
            $yield = RecipeGrams::from($attributes['yield_grams'])->value;
            $ingredients = $this->normalizeIngredients($attributes['ingredients']);
        } catch (InvalidArgumentException $exception) {
            throw new FeedRecipeConflict($exception->getMessage(), previous: $exception);
        }
        $key = Str::lower($attributes['idempotency_key']);
        $hash = hash('sha256', json_encode([$name, $notes, (string) $yield, $ingredients], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $source, $name, $notes, $yield, $ingredients, $key, $hash): FeedRecipe {
            $currentActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->first();
            if ($currentActor === null) {
                throw new AuthorizationException;
            }
            Gate::forUser($currentActor)->authorize('create', FeedRecipe::class);
            $existing = FeedRecipe::query()->where('created_by', $currentActor->getKey())->where('idempotency_key', $key)->sharedLock()->first();
            if ($existing !== null) {
                if ($existing->request_hash !== $hash) {
                    throw new FeedRecipeConflict('La clave de idempotencia ya fue utilizada con otros datos.');
                }

                return $existing->load('ingredients.product');
            }
            $products = Product::query()->whereIn('id', array_keys($ingredients))->orderBy('id')->sharedLock()->get();
            if ($products->count() !== count($ingredients)) {
                throw new FeedRecipeConflict('Todos los ingredientes deben existir en el catálogo de materias primas.');
            }
            foreach ($products as $product) {
                if ($product->kind !== ProductKind::RawMaterial || $product->base_unit !== BaseUnit::Gram || ! $product->stock_tracked) {
                    throw new FeedRecipeConflict('Los ingredientes deben ser materias primas del inventario con unidad base en gramos.');
                }
            }
            $recipe = new FeedRecipe;
            $recipe->forceFill([
                'name' => $name, 'notes' => $notes, 'yield_grams' => $yield,
                'status' => FeedRecipeStatus::Active, 'created_by' => $currentActor->getKey(),
                'operation_id' => (string) Str::uuid(), 'idempotency_key' => $key, 'request_hash' => $hash,
            ])->save();
            foreach ($ingredients as $productId => $quantity) {
                $ingredient = $recipe->ingredients()->make();
                $ingredient->forceFill(['product_id' => $productId, 'quantity_grams' => $quantity])->save();
            }
            $this->history->record($recipe, $currentActor, 'feed_recipe_created', 'Receta creada', [], $recipe->operation_id, $source);

            return $recipe;
        }, 3);
    }

    /**
     * Normaliza el orden y las cantidades para que el reintento no dependa del orden de los ingredientes.
     *
     * @param  array<array-key, array{product_id: int, quantity_grams: int|string}>  $ingredients
     * @return array<int, int>
     */
    private function normalizeIngredients(array $ingredients): array
    {
        if ($ingredients === [] || count($ingredients) > 1000 || ! array_is_list($ingredients)) {
            throw new InvalidArgumentException('Debes indicar entre 1 y 1000 ingredientes.');
        }
        $normalized = [];
        foreach ($ingredients as $ingredient) {
            if (array_diff(array_keys($ingredient), ['product_id', 'quantity_grams']) !== []) {
                throw new InvalidArgumentException('Los campos del ingrediente no están permitidos.');
            }
            $productId = RecipeGrams::from($ingredient['product_id'])->value;
            if (isset($normalized[$productId])) {
                throw new InvalidArgumentException('Cada ingrediente debe aparecer una sola vez.');
            }
            $normalized[$productId] = RecipeGrams::from($ingredient['quantity_grams'])->value;
        }
        ksort($normalized);

        return $normalized;
    }
}
