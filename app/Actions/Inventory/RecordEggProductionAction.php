<?php

namespace App\Actions\Inventory;

use App\DTO\Inventory\ProductionMovementData;
use App\Exceptions\Inventory\InventoryConflict;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;

/**
 * Fachada interna para registrar el efecto de una operación sobre la cuenta de huevos.
 */
final readonly class RecordEggProductionAction
{
    public function __construct(private RecordEggStockTransactionAction $stock) {}

    public function execute(ProductionUnit $unit, int $delta, string $operationId, User $actor, string $occurredAt, ?string $reason = null, string $source = 'api', string $type = 'manual_receipt'): ProductionMovementData
    {
        if ($delta === 0) {
            throw new InventoryConflict('La operación debe modificar el saldo.');
        }
        $result = $this->stock->execute($unit, $type, abs($delta), $operationId, $actor, $occurredAt, $reason, sign: $delta < 0 ? -1 : 1, source: $source);

        return new ProductionMovementData($result['movement']->id, $operationId);
    }
}
