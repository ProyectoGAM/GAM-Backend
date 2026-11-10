<?php

namespace App\Policies\FarmStructure;

use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;

final readonly class ProductionUnitPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'can_view_production_units');
    }

    public function view(User $user, ProductionUnit $productionUnit): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'can_manage_production_units');
    }

    public function update(User $user, ProductionUnit $productionUnit): bool
    {
        return $this->create($user);
    }

    public function changeStatus(User $user, ProductionUnit $productionUnit): bool
    {
        return $this->update($user, $productionUnit);
    }

    private function hasPermission(User $user, string $permission): bool
    {
        return $user->hasRole('admin') || $user->checkPermissionTo($permission);
    }
}
