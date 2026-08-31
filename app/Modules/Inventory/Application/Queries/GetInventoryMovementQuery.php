<?php

namespace App\Modules\Inventory\Application\Queries;

use App\Models\Inventory\InventoryMovement;

final readonly class GetInventoryMovementQuery
{
    public function execute(InventoryMovement $movement): InventoryMovement
    {
        return $movement->load(['lines.product', 'lines.stockLocation', 'supplier', 'creator']);
    }
}
