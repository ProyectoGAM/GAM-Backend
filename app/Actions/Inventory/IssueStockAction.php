<?php

namespace App\Actions\Inventory;

use App\DTO\Inventory\InventoryMovementCommand;
use App\Enums\Inventory\InventoryMovementType;
use App\Models\Inventory\InventoryMovement;
use App\Models\User;

final readonly class IssueStockAction
{
    public function __construct(private RecordInventoryMovementAction $recordMovement) {}

    /** @param array<string, mixed> $attributes */
    public function execute(array $attributes, User $actor): InventoryMovement
    {
        $lines = array_map(static fn (array $line): array => [
            'product_id' => (int) $line['product_id'],
            'stock_location_id' => (int) $line['stock_location_id'],
            'on_hand_delta' => '-'.ltrim((string) $line['quantity'], '+'),
        ], $attributes['lines']);

        return $this->recordMovement->execute(new InventoryMovementCommand(
            type: InventoryMovementType::Issue,
            lines: $lines,
            operationId: (string) $attributes['idempotency_key'],
            referenceType: $attributes['reference_type'] ?? null,
            referenceId: $attributes['reference_id'] ?? null,
            reason: $attributes['reason'] ?? null,
            occurredAt: $attributes['occurred_at'] ?? null,
        ), $actor);
    }
}
