<?php

namespace App\Policies\Inventory;

use App\Models\Inventory\FeedRecipe;
use App\Models\User;

final readonly class FeedRecipePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'feed-recipes.view');
    }

    public function view(User $user, FeedRecipe $recipe): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'feed-recipes.manage');
    }

    public function update(User $user, FeedRecipe $recipe): bool
    {
        return $this->create($user);
    }

    public function changeStatus(User $user, FeedRecipe $recipe): bool
    {
        return $this->create($user);
    }

    public function calculate(User $user, FeedRecipe $recipe): bool
    {
        return $this->viewAny($user);
    }

    private function allowed(User $user, string $permission): bool
    {
        return ! $user->trashed() && ($user->hasRole('admin') || $user->checkPermissionTo($permission));
    }
}
