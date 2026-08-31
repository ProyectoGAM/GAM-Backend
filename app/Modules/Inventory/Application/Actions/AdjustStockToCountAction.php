<?php

namespace App\Modules\Inventory\Application\Actions;

use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockLocation;
use App\Models\User;
use App\Modules\Inventory\Application\Data\InventoryMovementCommand;
use App\Modules\Inventory\Domain\Enums\InventoryMovementType;
use App\Modules\Inventory\Domain\Exceptions\InventoryConflict;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final readonly class AdjustStockToCountAction
{
    public function __construct(private RecordInventoryMovementAction $recordMovement) {}

    /** @param array<string, mixed> $attributes */
    public function execute(array $attributes, User $actor): InventoryMovement
    {
        $payload = [
            'type' => InventoryMovementType::Adjustment->value,
            'lines' => $attributes['lines'],
            'reason' => $attributes['reason'],
            'occurred_at' => $attributes['occurred_at'] ?? null,
        ];
        $operationId = (string) $attributes['idempotency_key'];
        $expectedHash = (new InventoryMovementCommand(type: InventoryMovementType::Adjustment, lines: [], operationId: $operationId, idempotencyPayload: $payload))->requestHash();
        $existing = InventoryMovement::query()->where('operation_id', $operationId)->first();
        if ($existing !== null) {
            if ($existing->request_hash !== $expectedHash) {
                throw new InventoryConflict('La clave Idempotency-Key ya fue utilizada con otros datos.');
            }

            return $existing->load(['lines.product', 'lines.stockLocation', 'supplier', 'creator']);
        }

        return DB::transaction(function () use ($attributes, $actor): InventoryMovement {
            $locationIds = array_values(array_unique(array_map(static fn (array $line): int => (int) $line['stock_location_id'], $attributes['lines'])));
            StockLocation::query()->whereIn('id', $locationIds)->orderBy('id')->lockForUpdate()->get();
            $keys = array_map(static fn (array $line): string => $line['product_id'].':'.$line['stock_location_id'], $attributes['lines']);
            $now = now();
            StockBalance::query()->insertOrIgnore(array_map(function (string $key) use ($now): array {
                [$productId, $locationId] = array_map('intval', explode(':', $key));

                return [
                    'product_id' => $productId,
                    'stock_location_id' => $locationId,
                    'on_hand_quantity' => '0.000000',
                    'minimum_quantity' => '0.000000',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, array_values(array_unique($keys))));

            $lines = [];
            foreach ($attributes['lines'] as $line) {
                $balance = StockBalance::query()
                    ->where('product_id', $line['product_id'])
                    ->where('stock_location_id', $line['stock_location_id'])
                    ->lockForUpdate()
                    ->firstOrFail();
                $delta = BigDecimal::of((string) $line['counted_quantity'])
                    ->minus((string) $balance->on_hand_quantity)
                    ->toScale(6);
                if (! $delta->isZero()) {
                    $lines[] = [
                        'product_id' => (int) $line['product_id'],
                        'stock_location_id' => (int) $line['stock_location_id'],
                        'on_hand_delta' => (string) $delta,
                    ];
                }
            }

            if ($lines === []) {
                throw new InventoryConflict('El ajuste no modifica ningún saldo.');
            }

            return $this->recordMovement->execute(new InventoryMovementCommand(
                type: InventoryMovementType::Adjustment,
                lines: $lines,
                operationId: (string) $attributes['idempotency_key'],
                reason: (string) $attributes['reason'],
                occurredAt: $attributes['occurred_at'] ?? null,
                idempotencyPayload: [
                    'type' => InventoryMovementType::Adjustment->value,
                    'lines' => $attributes['lines'],
                    'reason' => $attributes['reason'],
                    'occurred_at' => $attributes['occurred_at'] ?? null,
                ],
            ), $actor);
        }, 3);
    }
}
