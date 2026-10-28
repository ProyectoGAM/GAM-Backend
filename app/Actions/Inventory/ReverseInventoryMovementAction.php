<?php

namespace App\Actions\Inventory;

use App\DTO\Inventory\InventoryMovementCommand;
use App\Enums\Inventory\InventoryMovementType;
use App\Exceptions\Inventory\InventoryConflict;
use App\Models\Inventory\InventoryMovement;
use App\Models\User;
use App\Services\Lots\LotsAuthorization;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final readonly class ReverseInventoryMovementAction
{
    public function __construct(private RecordInventoryMovementAction $recordMovement) {}

    /** @param array<string, mixed> $attributes */
    public function execute(InventoryMovement $movement, array $attributes, User $actor, string $source = 'api', string $requiredPermission = 'inventory.adjust'): InventoryMovement
    {
        return DB::transaction(function () use ($movement, $attributes, $actor, $source, $requiredPermission): InventoryMovement {
            $currentActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            LotsAuthorization::ensure($currentActor, $requiredPermission);
            $locked = InventoryMovement::query()
                ->whereKey($movement->getKey())
                ->lockForUpdate()
                ->firstOrFail()
                ->load('lines');
            if ($locked->reference_type === 'stock_regularization') {
                throw new InventoryConflict('Una regularización no puede revertirse reintroduciendo stock incompatible.');
            }
            if (in_array($locked->reference_type, ['feed_manufacture', 'feed_manufacture_cancellation', 'feed_ingredient_adjustment'], true)) {
                throw new InventoryConflict('Los movimientos de alimento deben corregirse desde su operación de elaboración o ajuste.');
            }
            if (in_array($locked->reference_type, ['vaccination_application', 'medicine_application'], true) && $requiredPermission !== 'management-plans.execute') {
                throw new InventoryConflict('Los consumos sanitarios deben corregirse desde su aplicación.');
            }
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
                operationId: (string) ($attributes['operation_id'] ?? $attributes['idempotency_key']),
                referenceType: 'inventory_movement',
                referenceId: (string) $locked->getKey(),
                reason: (string) $attributes['reason'],
                reversesMovementId: (int) $locked->getKey(),
                idempotencyPayload: [
                    'type' => InventoryMovementType::Reversal->value,
                    'movement_id' => (int) $locked->getKey(),
                    'reason' => (string) $attributes['reason'],
                ],
            ), $actor, $source, requiredPermission: $requiredPermission,
                healthCompensation: in_array($locked->reference_type, ['vaccination_application', 'medicine_application'], true) ? $locked : null,
            );
        }, 3);
    }
}
