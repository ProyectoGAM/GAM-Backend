<?php

namespace App\Modules\SuppliersAndCatalogs\Domain\Enums;

enum ProductKind: string
{
    case RawMaterial = 'raw_material';
    case Supply = 'supply';
    case FinishedFeed = 'finished_feed';
    case Egg = 'egg';
    case Medicine = 'medicine';
    case Vaccine = 'vaccine';
    case Other = 'other';
}
