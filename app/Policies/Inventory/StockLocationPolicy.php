<?php

namespace App\Policies\Inventory;

use App\Models\Inventory\StockLocation;
use App\Models\User;

final readonly class StockLocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'inventory.view');
    }

    public function view(User $user, StockLocation $stockLocation): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'inventory.manage');
    }

    public function update(User $user, StockLocation $stockLocation): bool
    {
        return $this->create($user);
    }

    public function changeStatus(User $user, StockLocation $stockLocation): bool
    {
        return $this->create($user);
    }

    private function allowed(User $user, string $permission): bool
    {
        return $user->hasRole('admin') || $user->checkPermissionTo($permission);
    }
}
