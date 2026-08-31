<?php

namespace App\Modules\Inventory\Application\Actions;

use App\Models\Inventory\InventoryMovement;
use App\Models\User;
use App\Modules\Inventory\Application\Data\InventoryMovementCommand;
use App\Modules\Inventory\Domain\Enums\InventoryMovementType;
use App\Modules\Inventory\Domain\Exceptions\InventoryConflict;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final readonly class ReverseInventoryMovementAction
{
    public function __construct(private RecordInventoryMovementAction $recordMovement) {}

    /** @param array<string, mixed> $attributes */
    public function execute(InventoryMovement $movement, array $attributes, User $actor): InventoryMovement
    {
        return DB::transaction(function () use ($movement, $attributes, $actor): InventoryMovement {
            $locked = InventoryMovement::query()
                ->whereKey($movement->getKey())
                ->lockForUpdate()
                ->firstOrFail()
                ->load('lines');
            if (InventoryMovement::query()->where('reverses_movement_id', $locked->getKey())->exists()) {
                throw new InventoryConflict('El movimiento ya fue revertido.');
            }

            $lines = array_map(static fn ($line): array => [
                'product_id' => (int) $line->product_id,
                'stock_location_id' => (int) $line->stock_location_id,
                'on_hand_delta' => (string) BigDecimal::of((string) $line->on_hand_delta)->negated(),
            ], $locked->lines->all());

            return $this->recordMovement->execute(new InventoryMovementCommand(
                type: InventoryMovementType::Reversal,
                lines: $lines,
                operationId: (string) $attributes['idempotency_key'],
                referenceType: 'inventory_movement',
                referenceId: (string) $locked->getKey(),
                reason: (string) $attributes['reason'],
                reversesMovementId: (int) $locked->getKey(),
                idempotencyPayload: [
                    'type' => InventoryMovementType::Reversal->value,
                    'movement_id' => (int) $locked->getKey(),
                    'reason' => (string) $attributes['reason'],
                ],
            ), $actor);
        }, 3);
    }
}
