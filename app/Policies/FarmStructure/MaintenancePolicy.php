<?php

namespace App\Policies\FarmStructure;

use App\Models\FarmStructure\Maintenance;
use App\Models\User;

final readonly class MaintenancePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'can_view_poultry_houses');
    }

    public function view(User $user, Maintenance $maintenance): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'can_manage_poultry_houses');
    }

    public function update(User $user, Maintenance $maintenance): bool
    {
        return $this->create($user);
    }

    public function cancel(User $user, Maintenance $maintenance): bool
    {
        return $this->create($user);
    }

    private function hasPermission(User $user, string $permission): bool
    {
        return ! $user->trashed() && ($user->hasRole('admin') || $user->checkPermissionTo($permission));
    }
}
