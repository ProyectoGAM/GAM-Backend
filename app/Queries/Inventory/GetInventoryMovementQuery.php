<?php

namespace App\Queries\Inventory;

use App\Models\Inventory\InventoryMovement;

final readonly class GetInventoryMovementQuery
{
    public function execute(InventoryMovement $movement): InventoryMovement
    {
        return $movement->load(['lines.product', 'lines.stockLocation', 'supplier', 'creator']);
    }
}
