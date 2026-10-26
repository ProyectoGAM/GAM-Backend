<?php

namespace App\Enums\SuppliersAndCatalogs;

enum ProductKind: string
{
    case RawMaterial = 'raw_material';
    case Supply = 'supply';
    /** Se conserva para leer productos e instantáneas históricas durante la transición. */
    case FinishedFeed = 'finished_feed';
    case Egg = 'egg';
    case Medicine = 'medicine';
    case Vaccine = 'vaccine';
    case Other = 'other';

    /** @return list<self> */
    public static function creatableCases(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $kind): bool => $kind !== self::FinishedFeed));
    }
}
