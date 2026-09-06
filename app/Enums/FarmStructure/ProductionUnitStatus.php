<?php

namespace App\Enums\FarmStructure;

enum ProductionUnitStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function canTransitionTo(self $status): bool
    {
        return $this !== $status;
    }
}
