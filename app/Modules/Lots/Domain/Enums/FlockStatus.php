<?php

namespace App\Modules\Lots\Domain\Enums;

enum FlockStatus: string
{
    case Active = 'active';
    case Quarantined = 'quarantined';
    case Finished = 'finished';

    public function canTransitionTo(self $status): bool
    {
        return $this !== self::Finished && $status !== $this;
    }
}
