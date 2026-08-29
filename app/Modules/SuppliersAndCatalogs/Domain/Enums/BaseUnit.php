<?php

namespace App\Modules\SuppliersAndCatalogs\Domain\Enums;

enum BaseUnit: string
{
    case Unit = 'unit';
    case Kilogram = 'kg';
    case Gram = 'g';
    case Liter = 'l';
    case Milliliter = 'ml';
    case Dose = 'dose';

    public function allowsFraction(): bool
    {
        return ! in_array($this, [self::Unit, self::Dose], true);
    }
}
