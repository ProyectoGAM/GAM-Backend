<?php

namespace App\Policies\Inventory;

use App\Models\Inventory\InventoryMovement;
use App\Models\User;

final readonly class InventoryMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'inventory.view');
    }

    public function view(User $user, InventoryMovement $inventoryMovement): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'inventory.move');
    }

    public function adjust(User $user): bool
    {
        return $this->allowed($user, 'inventory.adjust');
    }

    public function reverse(User $user, InventoryMovement $inventoryMovement): bool
    {
        return $this->allowed($user, 'inventory.adjust');
    }

    private function allowed(User $user, string $permission): bool
    {
        return $user->hasRole('admin') || $user->checkPermissionTo($permission);
    }
}
