<?php

namespace App\Policies\FarmStructure;

use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;

final readonly class PoultryHousePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'poultry-houses.view');
    }

    public function view(User $user, PoultryHouse $poultryHouse): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user, ProductionUnit $productionUnit): bool
    {
        return $this->hasPermission($user, 'poultry-houses.manage');
    }

    public function update(User $user, PoultryHouse $poultryHouse): bool
    {
        return $this->hasPermission($user, 'poultry-houses.manage');
    }

    public function changeStatus(User $user, PoultryHouse $poultryHouse): bool
    {
        return $this->update($user, $poultryHouse);
    }

    private function hasPermission(User $user, string $permission): bool
    {
        return $user->hasRole('admin') || $user->checkPermissionTo($permission);
    }
}
