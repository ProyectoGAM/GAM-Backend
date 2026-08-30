<?php

namespace App\Modules\FarmStructure\Domain\Enums;

enum MaintenanceStatus: string
{
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $status): bool
    {
        return $this === self::Completed && $status === self::Cancelled;
    }
}
