<?php

namespace App\Enums\SuppliersAndCatalogs;

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
