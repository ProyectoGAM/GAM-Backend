<?php

namespace App\Policies\Inventory;

use App\Models\Inventory\StockReservation;
use App\Models\User;

final readonly class StockReservationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'inventory.view');
    }

    public function view(User $user, StockReservation $stockReservation): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user, 'inventory.reserve');
    }

    public function release(User $user, StockReservation $stockReservation): bool
    {
        return $this->create($user);
    }

    public function consume(User $user, StockReservation $stockReservation): bool
    {
        return $this->create($user);
    }

    private function allowed(User $user, string $permission): bool
    {
        return $user->hasRole('admin') || $user->checkPermissionTo($permission);
    }
}
