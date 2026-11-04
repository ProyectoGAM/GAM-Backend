<?php

namespace App\Policies\Inventory;

use App\Models\Inventory\StockBalance;
use App\Models\User;

final readonly class StockBalancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin') || $user->checkPermissionTo('can_view_inventory');
    }

    public function view(User $user, StockBalance $stockBalance): bool
    {
        return $this->viewAny($user);
    }

    public function manage(User $user, StockBalance $stockBalance): bool
    {
        return $user->hasRole('admin') || $user->checkPermissionTo('can_manage_inventory');
    }
}
