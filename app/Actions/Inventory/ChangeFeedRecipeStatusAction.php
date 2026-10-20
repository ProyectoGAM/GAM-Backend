<?php

namespace App\Actions\Inventory;

use App\Enums\Inventory\FeedRecipeStatus;
use App\Models\Inventory\FeedRecipe;
use App\Models\User;
use App\Services\Inventory\FeedRecipeHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final readonly class ChangeFeedRecipeStatusAction
{
    public function __construct(private FeedRecipeHistory $history) {}

    public function execute(FeedRecipe $recipe, FeedRecipeStatus $status, User $actor, string $source = 'api'): FeedRecipe
    {
        Gate::forUser($actor)->authorize('changeStatus', $recipe);

        return DB::transaction(function () use ($recipe, $status, $actor, $source): FeedRecipe {
            $locked = FeedRecipe::query()->whereKey($recipe->getKey())->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('changeStatus', $locked);
            $locked->load('ingredients.product');
            if ($locked->status === $status) {
                return $locked;
            }
            $before = $this->history->snapshot($locked);
            $locked->forceFill(['status' => $status])->save();
            $this->history->record($locked, $actor, 'feed_recipe_status_changed', 'Estado de receta actualizado', $before, (string) Str::uuid(), $source);

            return $locked;
        }, 3);
    }
}
