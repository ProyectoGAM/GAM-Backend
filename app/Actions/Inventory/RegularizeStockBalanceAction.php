<?php

namespace App\Actions\Inventory;

use App\DTO\Inventory\InventoryMovementCommand;
use App\Enums\Inventory\InventoryMovementType;
use App\Exceptions\Inventory\InventoryConflict;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\StockBalance;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use App\Services\Inventory\StockLocationLocks;
use App\Services\Lots\LotsAuthorization;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class RegularizeStockBalanceAction
{
    public function __construct(private RecordInventoryMovementAction $movements, private StockLocationLocks $locationLocks) {}

    /** @param array{mode:string, reason:string, idempotency_key:string, to_stock_location_id?:int, quantity?:string} $attributes */
    public function execute(StockBalance $balance, array $attributes, User $actor): InventoryMovement
    {
        return DB::transaction(function () use ($balance, $attributes, $actor): InventoryMovement {
            $actor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            LotsAuthorization::ensure($actor, 'inventory.adjust');
            if (! Str::isUuid($attributes['idempotency_key']) || trim($attributes['reason']) === '' || ! in_array($attributes['mode'], ['trasladar', 'recontar'], true)) {
                throw new InventoryConflict('La regularización requiere modo, motivo y clave de idempotencia válidos.');
            }
            $payload = ['balance_id' => (int) $balance->getKey(), ...$attributes];
            unset($payload['idempotency_key']);
            $replay = InventoryMovement::query()->where('operation_id', $attributes['idempotency_key'])->first();
            if ($replay !== null) {
                return $this->movements->execute(new InventoryMovementCommand(
                    type: $attributes['mode'] === 'trasladar' ? InventoryMovementType::Transfer : InventoryMovementType::Adjustment,
                    lines: [], operationId: $attributes['idempotency_key'], idempotencyPayload: $payload,
                ), $actor, requiredPermission: 'inventory.adjust');
            }
            Product::query()->whereKey($balance->product_id)->sharedLock()->firstOrFail();
            $locationIds = [(int) $balance->stock_location_id, (int) ($attributes['to_stock_location_id'] ?? $balance->stock_location_id)];
            $this->locationLocks->lock($locationIds);
            $locked = StockBalance::query()->whereKey($balance->getKey())->lockForUpdate()->firstOrFail();
            $delta = BigDecimal::of((string) $locked->on_hand_quantity)->negated();
            $lines = [];
            if ($attributes['mode'] === 'trasladar') {
                $quantity = BigDecimal::of($attributes['quantity']);
                if ($quantity->compareTo(0) <= 0 || (int) $attributes['to_stock_location_id'] === (int) $locked->stock_location_id) {
                    throw new InventoryConflict('El traslado requiere una cantidad positiva y un destino diferente.');
                }
                $delta = $quantity->negated();
                $lines[] = ['product_id' => (int) $locked->product_id, 'stock_location_id' => (int) $attributes['to_stock_location_id'], 'on_hand_delta' => (string) $quantity];
            }
            $lines[] = ['product_id' => (int) $locked->product_id, 'stock_location_id' => (int) $locked->stock_location_id, 'on_hand_delta' => (string) $delta];

            return $this->movements->execute(new InventoryMovementCommand(
                type: $attributes['mode'] === 'trasladar' ? InventoryMovementType::Transfer : InventoryMovementType::Adjustment,
                lines: $lines, operationId: $attributes['idempotency_key'], reason: trim($attributes['reason']),
                referenceType: 'stock_regularization', referenceId: (string) $locked->getKey(), idempotencyPayload: $payload,
            ), $actor, regularization: $locked, requiredPermission: 'inventory.adjust');
        }, 3);
    }
}
