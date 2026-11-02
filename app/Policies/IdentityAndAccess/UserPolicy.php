<?php

namespace App\Policies\IdentityAndAccess;

use App\Models\User;

final class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return ! $actor->trashed() && ($actor->hasRole('admin') || $actor->can('can_manage_users'));
    }

    public function create(User $actor): bool
    {
        return ! $actor->trashed() && $actor->hasRole('admin');
    }

    public function assignRole(User $actor, User $subject): bool
    {
        return $this->create($actor);
    }

    public function update(User $actor, User $subject): bool
    {
        return $this->allows($actor, $subject, 'can_manage_users');
    }

    public function managePin(User $actor, User $subject): bool
    {
        return $this->allows($actor, $subject, 'can_manage_user_pins');
    }

    public function revokeSessions(User $actor, User $subject): bool
    {
        return $this->allows($actor, $subject, 'can_manage_user_sessions');
    }

    private function allows(User $actor, User $subject, string $permission): bool
    {
        return ! $actor->trashed() && ($actor->hasRole('admin')
            || (! $subject->hasRole('admin') && $actor->can($permission)));
    }
}
