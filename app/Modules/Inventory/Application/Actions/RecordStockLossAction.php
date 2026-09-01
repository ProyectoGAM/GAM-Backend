<?php

namespace App\Modules\Inventory\Application\Actions;

use App\Models\Inventory\InventoryMovement;
use App\Models\User;
use App\Modules\Inventory\Application\Data\InventoryMovementCommand;
use App\Modules\Inventory\Domain\Enums\InventoryMovementType;

final readonly class RecordStockLossAction
{
    public function __construct(private RecordInventoryMovementAction $recordMovement) {}

    /** @param array<string, mixed> $attributes */
    public function execute(array $attributes, User $actor, string $source = 'api'): InventoryMovement
    {
        $lines = array_map(static fn (array $line): array => [
            'product_id' => (int) $line['product_id'],
            'stock_location_id' => (int) $line['stock_location_id'],
            'on_hand_delta' => '-'.ltrim((string) $line['quantity'], '+'),
        ], $attributes['lines']);

        return $this->recordMovement->execute(new InventoryMovementCommand(
            type: InventoryMovementType::Loss,
            lines: $lines,
            operationId: (string) ($attributes['operation_id'] ?? $attributes['idempotency_key']),
            referenceType: $attributes['reference_type'] ?? null,
            referenceId: $attributes['reference_id'] ?? null,
            reason: (string) $attributes['reason'],
            occurredAt: $attributes['occurred_at'] ?? null,
        ), $actor, $source);
    }
}
