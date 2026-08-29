<?php

namespace App\Modules\Inventory\Domain\Enums;

enum StockReservationStatus: string
{
    case Active = 'active';
    case PartiallyConsumed = 'partially_consumed';
    case Released = 'released';
    case Consumed = 'consumed';
}
