<?php

namespace App\Modules\Inventory\Application\PublicApi\Actions;

use App\Models\User;
use App\Modules\Inventory\Application\Actions\RecordInventoryMovementAction;
use App\Modules\Inventory\Application\Data\InventoryMovementCommand;
use App\Modules\Inventory\Application\PublicApi\Data\ProductionMovementData;
use App\Modules\Inventory\Domain\Enums\InventoryMovementType;
use App\Modules\Inventory\Domain\Exceptions\InventoryConflict;
use App\Modules\SuppliersAndCatalogs\Application\PublicApi\Queries\GetActiveProductQuery;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\BaseUnit;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\ProductKind;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class RecordEggProductionAction
{
    public function __construct(private RecordInventoryMovementAction $record, private GetActiveProductQuery $products) {}

    public function execute(int $productId, int $locationId, int $delta, string $collectionId, string $operationId, string $occurredAt, User $actor, ?string $reason = null, string $source = 'api'): ?ProductionMovementData
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('La producción requiere la transacción del módulo propietario.');
        }
        if ($delta === 0) {
            return null;
        }
        $product = $this->products->execute($productId);
        if ($product === null || $product->kind !== ProductKind::Egg || $product->baseUnit !== BaseUnit::Unit || ! $product->stockTracked) {
            throw new InventoryConflict('La recolección requiere un producto de huevos activo, inventariable y medido en unidades.');
        }
        $movement = $this->record->execute(new InventoryMovementCommand(
            type: $delta > 0 ? InventoryMovementType::Receipt : InventoryMovementType::Adjustment,
            lines: [['product_id' => $productId, 'stock_location_id' => $locationId, 'on_hand_delta' => (string) $delta, 'reserved_delta' => '0']],
            operationId: $operationId,
            referenceType: 'egg_collection',
            referenceId: $collectionId,
            reason: $reason,
            occurredAt: $occurredAt,
        ), $actor, $source);

        return new ProductionMovementData((int) $movement->getKey(), $operationId);
    }
}
