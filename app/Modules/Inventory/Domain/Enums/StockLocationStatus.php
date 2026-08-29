<?php

namespace App\Modules\Inventory\Domain\Enums;

enum StockLocationStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
