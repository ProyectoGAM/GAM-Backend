<?php

namespace App\Queries\Inventory;

use App\Models\Inventory\InventoryMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final readonly class GetInventoryMovementQuery
{
    public function execute(InventoryMovement $movement): InventoryMovement
    {
        return $movement->load([
            'lines.product' => static fn (BelongsTo $productRelation): Builder => $productRelation->getQuery()->withResourceMetadata(),
            'lines.stockLocation',
            'supplier',
            'creator',
        ]);
    }
}
