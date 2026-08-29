<?php

namespace App\Policies\Geography;

use App\Models\Geography\Locality;
use App\Models\User;

final readonly class LocalityPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, 'geography.view');
    }

    public function view(User $user, Locality $locality): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, 'geography.manage');
    }

    public function update(User $user, Locality $locality): bool
    {
        return $this->create($user);
    }

    private function hasPermission(User $user, string $permission): bool
    {
        return $user->hasRole('admin') || $user->checkPermissionTo($permission);
    }
}
