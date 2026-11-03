<?php

namespace App\Services\IdentityAndAccess;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

final class ProductionUnitAccess
{
    private bool $overridden = false;

    private ?int $override = null;

    public function unitId(): ?int
    {
        if ($this->overridden) {
            return $this->override;
        }
        $user = Auth::user();

        return $user instanceof User && $user->usesDevicePin() ? $user->production_unit_id : null;
    }

    public function assertUnit(int $unitId, ?User $actor = null): void
    {
        $expected = $actor?->usesDevicePin() ? $actor->production_unit_id : $this->unitId();
        abort_if($expected !== null && $expected !== $unitId, 404);
    }

    public function allows(User $actor, Model $subject): bool
    {
        return $this->within($actor->usesDevicePin() ? $actor->production_unit_id : null,
            fn (): bool => $subject->newQuery()->whereKey($subject->getKey())->exists());
    }

    public function within(?int $unitId, callable $operation): mixed
    {
        $previous = [$this->overridden, $this->override];
        $this->overridden = true;
        $this->override = $unitId;
        try {
            return $operation();
        } finally {
            [$this->overridden, $this->override] = $previous;
        }
    }
}
