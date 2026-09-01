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

    /**
     * Mantiene la frontera anterior para clientes internos que aún registran una sola clasificación.
     */
    public function execute(int $productId, int $locationId, int $delta, string $collectionId, string $operationId, string $occurredAt, User $actor, ?string $reason = null, string $source = 'api'): ?ProductionMovementData
    {
        return $this->executeLines(
            [['product_id' => $productId, 'stock_location_id' => $locationId, 'on_hand_delta' => (string) $delta]],
            $collectionId,
            $operationId,
            $occurredAt,
            $actor,
            $reason,
            $source,
            $delta > 0 ? InventoryMovementType::Receipt : InventoryMovementType::Adjustment,
        );
    }

    /**
     * Registra o ajusta todas las clasificaciones de una recolección en un único movimiento.
     *
     * @param  list<array{product_id:int, stock_location_id:int, on_hand_delta:string|int}>  $lines
     */
    public function executeLines(array $lines, string $collectionId, string $operationId, string $occurredAt, User $actor, ?string $reason = null, string $source = 'api', InventoryMovementType $type = InventoryMovementType::Receipt): ?ProductionMovementData
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('La producción requiere la transacción del módulo propietario.');
        }
        $lines = array_values(array_filter($lines, static fn (array $line): bool => (int) $line['on_hand_delta'] !== 0));
        if ($lines === []) {
            return null;
        }

        foreach ($lines as $line) {
            $product = $this->products->execute((int) $line['product_id']);
            if ($product === null || $product->kind !== ProductKind::Egg || $product->baseUnit !== BaseUnit::Unit || ! $product->stockTracked) {
                throw new InventoryConflict('La recolección requiere productos de huevos activos, inventariables y medidos en unidades.');
            }
        }

        $movement = $this->record->execute(new InventoryMovementCommand(
            type: $type,
            lines: array_map(static fn (array $line): array => [
                'product_id' => (int) $line['product_id'],
                'stock_location_id' => (int) $line['stock_location_id'],
                'on_hand_delta' => (string) $line['on_hand_delta'],
            ], $lines),
            operationId: $operationId,
            referenceType: 'egg_collection',
            referenceId: $collectionId,
            reason: $reason,
            occurredAt: $occurredAt,
        ), $actor, $source);

        return new ProductionMovementData((int) $movement->getKey(), $operationId);
    }
}
