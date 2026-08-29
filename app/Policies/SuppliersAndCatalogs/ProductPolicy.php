<?php

namespace App\Policies\SuppliersAndCatalogs;

use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;

final readonly class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'products.view');
    }

    public function view(User $user, Product $product): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'products.manage');
    }

    public function update(User $user, Product $product): bool
    {
        return $this->create($user);
    }

    public function changeStatus(User $user, Product $product): bool
    {
        return $this->create($user);
    }

    private function allowed(User $user, string $permission): bool
    {
        return $user->hasRole('admin') || $user->checkPermissionTo($permission);
    }
}
