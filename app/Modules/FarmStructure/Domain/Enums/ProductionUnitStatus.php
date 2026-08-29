<?php

namespace App\Modules\FarmStructure\Domain\Enums;

enum ProductionUnitStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function canTransitionTo(self $status): bool
    {
        return $this !== $status;
    }
}
