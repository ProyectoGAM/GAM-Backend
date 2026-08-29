<?php

namespace App\Modules\Inventory\Application\Actions;

use App\Models\Inventory\InventoryMovement;
use App\Models\User;
use App\Modules\Inventory\Application\Data\InventoryMovementCommand;
use App\Modules\Inventory\Domain\Enums\InventoryMovementType;
use Brick\Math\BigDecimal;

final readonly class TransferStockAction
{
    public function __construct(private RecordInventoryMovementAction $recordMovement) {}

    /** @param array<string, mixed> $attributes */
    public function execute(array $attributes, User $actor): InventoryMovement
    {
        $lines = [];
        foreach ($attributes['lines'] as $line) {
            $quantity = (string) $line['quantity'];
            $lines[] = [
                'product_id' => (int) $line['product_id'],
                'stock_location_id' => (int) $line['from_stock_location_id'],
                'on_hand_delta' => '-'.ltrim($quantity, '+'),
                'reserved_delta' => '0',
            ];
            $lines[] = [
                'product_id' => (int) $line['product_id'],
                'stock_location_id' => (int) $line['to_stock_location_id'],
                'on_hand_delta' => $quantity,
                'reserved_delta' => '0',
            ];
        }

        $lines = $this->mergeLines($lines);

        return $this->recordMovement->execute(new InventoryMovementCommand(
            type: InventoryMovementType::Transfer,
            lines: $lines,
            operationId: (string) $attributes['idempotency_key'],
            reason: $attributes['reason'] ?? null,
            occurredAt: $attributes['occurred_at'] ?? null,
        ), $actor);
    }

    /** @param list<array{product_id:int, stock_location_id:int, on_hand_delta:string, reserved_delta:string}> $lines */
    private function mergeLines(array $lines): array
    {
        $merged = [];
        foreach ($lines as $line) {
            $key = $line['product_id'].':'.$line['stock_location_id'];
            if (! isset($merged[$key])) {
                $merged[$key] = $line;

                continue;
            }
            $merged[$key]['on_hand_delta'] = (string) BigDecimal::of($merged[$key]['on_hand_delta'])->plus($line['on_hand_delta']);
            $merged[$key]['reserved_delta'] = (string) BigDecimal::of($merged[$key]['reserved_delta'])->plus($line['reserved_delta']);
        }

        return array_values(array_filter($merged, static fn (array $line): bool => ! BigDecimal::of($line['on_hand_delta'])->isZero()
            || ! BigDecimal::of($line['reserved_delta'])->isZero()));
    }
}
