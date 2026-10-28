<?php

namespace App\Enums\Inventory;

use App\Enums\SuppliersAndCatalogs\ProductKind;

enum StockLocationType: string
{
    case Warehouse = 'warehouse';
    case FeedPlant = 'feed_plant';
    case EggAccount = 'egg_account';
    case Health = 'health';
    case Unknown = 'unknown';

    /** @return list<ProductKind> */
    public function allowedKinds(): array
    {
        return match ($this) {
            self::Warehouse => [ProductKind::Supply, ProductKind::Other],
            self::FeedPlant => [ProductKind::RawMaterial],
            self::EggAccount => [ProductKind::Egg],
            self::Health => [ProductKind::Medicine, ProductKind::Vaccine],
            self::Unknown => [],
        };
    }
}
