<?php

namespace App\Modules\SuppliersAndCatalogs\Domain\Enums;

enum ProductStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
