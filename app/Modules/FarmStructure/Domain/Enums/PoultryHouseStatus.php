<?php

namespace App\Modules\FarmStructure\Domain\Enums;

enum PoultryHouseStatus: string
{
    case Operational = 'operational';
    case Maintenance = 'maintenance';
    case OutOfService = 'out_of_service';
    case Inactive = 'inactive';

    public function canTransitionTo(self $status): bool
    {
        if ($this === $status) {
            return false;
        }

        return match ($this) {
            self::Operational => true,
            self::Maintenance => in_array($status, [self::Operational, self::OutOfService, self::Inactive], true),
            self::OutOfService => in_array($status, [self::Operational, self::Maintenance, self::Inactive], true),
            self::Inactive => $status === self::Operational,
        };
    }
}
