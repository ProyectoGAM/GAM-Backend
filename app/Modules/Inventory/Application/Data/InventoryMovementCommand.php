<?php

namespace App\Modules\Inventory\Application\Data;

use App\Modules\Inventory\Domain\Enums\InventoryMovementType;
use JsonException;

final readonly class InventoryMovementCommand
{
    /**
     * @param  list<array{product_id:int, stock_location_id:int, on_hand_delta:string}>  $lines
     * @param  array<string, mixed>|null  $idempotencyPayload
     */
    public function __construct(
        public InventoryMovementType $type,
        public array $lines,
        public string $operationId,
        public ?int $supplierId = null,
        public ?string $referenceType = null,
        public ?string $referenceId = null,
        public ?string $reason = null,
        public ?string $occurredAt = null,
        public ?int $reversesMovementId = null,
        public ?array $idempotencyPayload = null,
    ) {}

    /** @throws JsonException */
    public function requestHash(): string
    {
        $lines = $this->lines;
        usort($lines, static fn (array $left, array $right): int => [$left['stock_location_id'], $left['product_id']] <=> [$right['stock_location_id'], $right['product_id']]);

        return hash('sha256', json_encode([
            'command' => $this->idempotencyPayload ?? [
                'type' => $this->type->value,
                'lines' => $lines,
                'supplier_id' => $this->supplierId,
                'reference_type' => $this->referenceType,
                'reference_id' => $this->referenceId,
                'reason' => $this->reason,
                'occurred_at' => $this->occurredAt,
                'reverses_movement_id' => $this->reversesMovementId,
            ],
        ], JSON_THROW_ON_ERROR));
    }
}
