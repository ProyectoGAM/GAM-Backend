<?php

namespace App\Policies\SuppliersAndCatalogs;

use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;

final readonly class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'can_view_suppliers');
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'can_manage_suppliers');
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $this->create($user);
    }

    public function changeStatus(User $user, Supplier $supplier): bool
    {
        return $this->create($user);
    }

    private function allowed(User $user, string $permission): bool
    {
        return $user->hasRole('admin') || $user->checkPermissionTo($permission);
    }
}
