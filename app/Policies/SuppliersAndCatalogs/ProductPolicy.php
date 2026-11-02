<?php

namespace App\Policies\SuppliersAndCatalogs;

use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;

final readonly class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->trashed() && ($this->allowed($user, 'can_view_products') || $user->can('can_manage_management_plans'));
    }

    public function view(User $user, Product $product): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'can_manage_products');
    }

    public function update(User $user, Product $product): bool
    {
        if ($this->hasVaccine($product)) {
            return ! $user->trashed() && $user->hasRole('admin');
        }

        return $this->create($user);
    }

    public function changeStatus(User $user, Product $product): bool
    {
        if ($this->hasVaccine($product)) {
            return ! $user->trashed() && $user->hasRole('admin');
        }

        return $this->create($user);
    }

    private function allowed(User $user, string $permission): bool
    {
        return $user->hasRole('admin') || $user->checkPermissionTo($permission);
    }

    private function hasVaccine(Product $product): bool
    {
        return $product->relationLoaded('vaccine')
            ? $product->getRelation('vaccine') !== null
            : $product->vaccine()->exists();
    }
}
