<?php

namespace App\Policies\Inventory;

use App\Models\User;

final class FeedStockOperationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'can_view_feed_stock');
    }

    public function manufacture(User $user): bool
    {
        return $this->allows($user, 'can_manufacture_feed');
    }

    public function adjustFeed(User $user): bool
    {
        return $this->allows($user, 'can_adjust_feed_stock');
    }

    public function adjustIngredient(User $user): bool
    {
        return $this->allows($user, 'can_adjust_feed_ingredients');
    }

    public function cancel(User $user): bool
    {
        return $this->allows($user, 'can_cancel_feed_manufacturing');
    }

    public function manageMinimums(User $user): bool
    {
        return $this->allows($user, 'can_manage_feed_minimums');
    }

    public function viewFeeding(User $user): bool
    {
        return $this->allows($user, 'can_view_flock_feeding');
    }

    public function manageFeeding(User $user): bool
    {
        return $this->allows($user, 'can_manage_flock_feeding');
    }

    public function correctConsumption(User $user): bool
    {
        return $this->allows($user, 'can_correct_feed_consumption');
    }

    private function allows(User $user, string $permission): bool
    {
        return ! $user->trashed() && ($user->hasRole('admin') || $user->can($permission));
    }
}
