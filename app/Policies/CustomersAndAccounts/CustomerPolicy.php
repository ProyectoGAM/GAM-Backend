<?php

namespace App\Policies\CustomersAndAccounts;

use App\Models\User;

final class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'can_view_customers') || $this->allows($user, 'can_manage_customers');
    }

    public function manage(User $user): bool
    {
        return $this->allows($user, 'can_manage_customers');
    }

    public function account(User $user): bool
    {
        return $this->allows($user, 'can_view_customer_accounts');
    }

    public function pay(User $user): bool
    {
        return $this->allows($user, 'can_record_customer_payments');
    }

    public function adjust(User $user): bool
    {
        return ! $user->trashed() && $user->hasRole('admin') && $this->allows($user, 'can_adjust_customer_accounts');
    }

    public function reverse(User $user): bool
    {
        return ! $user->trashed() && $user->hasRole('admin') && $this->allows($user, 'can_reverse_customer_accounts');
    }

    private function allows(User $user, string $permission): bool
    {
        return ! $user->trashed() && ($user->hasRole('admin') || $user->can($permission));
    }
}
