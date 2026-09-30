<?php

namespace App\Enums\Inventory;

enum StockLocationStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
