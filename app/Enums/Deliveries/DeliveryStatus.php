<?php

namespace App\Enums\Deliveries;

enum DeliveryStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function isClosed(): bool
    {
        return $this !== self::Active;
    }
}
