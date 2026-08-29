<?php

namespace App\Modules\SuppliersAndCatalogs\Domain\Enums;

enum SupplierStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
