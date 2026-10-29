<?php

namespace App\Policies\CustomersAndAccounts;

use App\Models\User;

final class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'customers.view') || $this->allows($user, 'customers.manage');
    }

    public function manage(User $user): bool
    {
        return $this->allows($user, 'customers.manage');
    }

    public function account(User $user): bool
    {
        return $this->allows($user, 'customer-accounts.view');
    }

    public function pay(User $user): bool
    {
        return $this->allows($user, 'customer-payments.record');
    }

    public function adjust(User $user): bool
    {
        return ! $user->trashed() && $user->hasRole('admin') && $this->allows($user, 'customer-accounts.adjust');
    }

    public function reverse(User $user): bool
    {
        return ! $user->trashed() && $user->hasRole('admin') && $this->allows($user, 'customer-accounts.reverse');
    }

    private function allows(User $user, string $permission): bool
    {
        return ! $user->trashed() && ($user->hasRole('admin') || $user->can($permission));
    }
}
