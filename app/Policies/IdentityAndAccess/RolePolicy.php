<?php

namespace App\Policies\IdentityAndAccess;

use App\Models\IdentityAndAccess\Role;
use App\Models\User;

final class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->trashed() && $user->hasRole('admin');
    }

    public function view(User $user, Role $role): bool
    {
        return $this->viewAny($user) && $role->guard_name === 'web';
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Role $role): bool
    {
        return $this->view($user, $role) && $role->name !== 'admin';
    }

    public function delete(User $user, Role $role): bool
    {
        return $this->view($user, $role) && ! $role->isProtected();
    }
}
