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
            // Futuro: sanidad conserva su tipo histórico, pero hoy no admite movimientos nuevos.
            self::Health => config('inventory.health_stock_enabled') ? [ProductKind::Medicine, ProductKind::Vaccine] : [],
            self::Unknown => [],
        };
    }
}
