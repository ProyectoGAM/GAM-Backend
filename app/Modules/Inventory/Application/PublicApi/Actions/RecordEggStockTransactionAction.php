<?php

namespace App\Modules\Inventory\Application\PublicApi\Actions;

use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\EggStockTransaction;
use App\Models\Inventory\InventoryMovement;
use App\Models\User;
use App\Modules\Inventory\Application\Actions\RecordInventoryMovementAction;
use App\Modules\Inventory\Application\Data\InventoryMovementCommand;
use App\Modules\Inventory\Domain\Enums\InventoryMovementType;
use App\Modules\Inventory\Domain\Exceptions\InventoryConflict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class RecordEggStockTransactionAction
{
    public function __construct(
        private EnsureEggStockAccountAction $accounts,
        private RecordInventoryMovementAction $inventory,
    ) {}

    /**
     * Registra la proyección lógica y su movimiento físico en la misma transacción.
     *
     * @return array{transaction:EggStockTransaction,movement:InventoryMovement}
     */
    public function execute(
        ProductionUnit $unit,
        string $type,
        int $quantity,
        string $operationId,
        User $actor,
        ?string $occurredAt = null,
        ?string $reason = null,
        ?string $notes = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        int $sign = 1,
        string $source = 'api',
    ): array {
        if ($quantity < 1 || $quantity > 2147483647) {
            throw new InventoryConflict('La cantidad debe ser un entero entre 1 y 2147483647.');
        }

        return DB::transaction(function () use ($unit, $type, $quantity, $operationId, $actor, $occurredAt, $reason, $notes, $referenceType, $referenceId, $sign, $source): array {
            $account = $this->accounts->execute($unit);
            $transaction = new EggStockTransaction;
            $transaction->forceFill([
                'public_id' => (string) Str::ulid(),
                'production_unit_id' => $unit->getKey(),
                'egg_stock_account_id' => $account->getKey(),
                'type' => $type,
                'quantity' => $quantity,
                'occurred_at' => $occurredAt ?? now(),
                'reason' => $reason,
                'notes' => $notes,
                'status' => 'recorded',
                'version' => 1,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'created_by' => $actor->getKey(),
            ])->save();

            $movement = $this->inventory->execute(new InventoryMovementCommand(
                type: $this->movementType($type, $sign),
                lines: [[
                    'product_id' => $account->product_id,
                    'stock_location_id' => $account->stock_location_id,
                    'on_hand_delta' => (string) ($quantity * $sign),
                ]],
                operationId: $operationId,
                referenceType: 'egg_stock_transaction',
                referenceId: $transaction->public_id,
                reason: $reason,
                occurredAt: $transaction->occurred_at->toIso8601String(),
                eggAccountOperation: true,
            ), $actor, $source);

            return ['transaction' => $transaction->load(['account', 'revisions']), 'movement' => $movement];
        }, 3);
    }

    public function adjust(EggStockTransaction $transaction, int $delta, string $operationId, User $actor, ?string $occurredAt = null, ?string $reason = null, string $source = 'api'): InventoryMovement
    {
        if ($delta === 0) {
            throw new InventoryConflict('El ajuste debe modificar el saldo.');
        }
        $account = $transaction->account()->firstOrFail();

        return $this->inventory->execute(new InventoryMovementCommand(
            type: InventoryMovementType::Adjustment,
            lines: [[
                'product_id' => $account->product_id,
                'stock_location_id' => $account->stock_location_id,
                'on_hand_delta' => (string) $delta,
            ]],
            operationId: $operationId,
            referenceType: 'egg_stock_revision',
            referenceId: $transaction->public_id,
            reason: $reason,
            occurredAt: $occurredAt ?? $transaction->occurred_at->toIso8601String(),
            eggAccountOperation: true,
        ), $actor, $source);
    }

    private function movementType(string $logicalType, int $sign): InventoryMovementType
    {
        if ($sign < 0) {
            return match ($logicalType) {
                'distribution_preparation' => InventoryMovementType::Issue,
                'loss' => InventoryMovementType::Loss,
                default => InventoryMovementType::Adjustment,
            };
        }

        return match ($logicalType) {
            'collection_receipt', 'manual_receipt' => InventoryMovementType::Receipt,
            'distribution_preparation' => InventoryMovementType::Issue,
            'loss' => InventoryMovementType::Loss,
            default => throw new InventoryConflict('El tipo de movimiento de huevos no es válido.'),
        };
    }
}
