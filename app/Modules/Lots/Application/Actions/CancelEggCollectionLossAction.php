<?php

namespace App\Modules\Lots\Application\Actions;

use App\Models\Inventory\InventoryMovement;
use App\Models\Lots\EggCollection;
use App\Models\Lots\FlockOperation;
use App\Models\User;
use App\Modules\Inventory\Application\Actions\ReverseInventoryMovementAction;
use App\Modules\Inventory\Domain\Exceptions\InventoryConflict;
use App\Modules\Lots\Application\Services\LotsHistory;
use App\Modules\Lots\Application\Services\LotsSnapshots;
use App\Modules\Lots\Application\Services\RunLotsCommand;
use App\Modules\Lots\Domain\Exceptions\LotsConflict;

final readonly class CancelEggCollectionLossAction
{
    public function __construct(
        private RunLotsCommand $commands,
        private ReverseInventoryMovementAction $inventory,
        private LotsSnapshots $snapshots,
        private LotsHistory $history,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(EggCollection $record, InventoryMovement $movement, array $data, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute($actor, 'egg-collections.manage', 'eggs.loss.cancel', $data['idempotency_key'], ['record' => $record->public_id, 'movement' => $movement->getKey(), ...$data], function (string $operationId) use ($record, $movement, $data, $actor, $source): array {
            $current = EggCollection::query()->whereKey($record->id)->lockForUpdate()->firstOrFail()->load(['flock', 'lines', 'inventoryLosses.lines']);
            $original = InventoryMovement::query()->whereKey($movement->getKey())->lockForUpdate()->firstOrFail()->load('lines');
            if ($current->status !== 'recorded' || $original->reference_type !== 'egg_collection' || $original->reference_id !== $current->public_id || $original->type->value !== 'loss') {
                throw new LotsConflict('El movimiento no corresponde a una pérdida activa de esta recolección.');
            }
            $before = $this->snapshots->collection($current, $current->flock);
            try {
                $reversal = $this->inventory->execute($original, [
                    'idempotency_key' => $data['idempotency_key'],
                    'operation_id' => $operationId,
                    'reason' => $data['reason'],
                ], $actor, $source);
            } catch (InventoryConflict $exception) {
                throw new LotsConflict($exception->getMessage(), previous: $exception);
            }
            $reversal->load('lines');
            $after = $this->snapshots->collection($current->fresh()->load(['flock', 'lines', 'inventoryLosses.lines']), $current->flock);
            $this->history->audit($current, $actor, 'egg_collection_loss_cancelled', 'Pérdida posterior de huevos cancelada', $operationId, $before, $after, $current->production_unit_id, $source, $data['reason']);

            return ['collection' => $after, 'loss' => $this->movementSnapshot($original), 'reversal' => $this->movementSnapshot($reversal)];
        });
    }

    /** @return array<string, mixed> */
    private function movementSnapshot(InventoryMovement $movement): array
    {
        return [
            'id' => (int) $movement->getKey(),
            'operation_id' => $movement->operation_id,
            'type' => $movement->type->value,
            'reference_type' => $movement->reference_type,
            'reference_id' => $movement->reference_id,
            'reason' => $movement->reason,
            'occurred_at' => $movement->occurred_at->toIso8601String(),
            'created_by' => $movement->created_by,
            'reverses_movement_id' => $movement->reverses_movement_id,
            'lines' => $movement->lines->map(static fn ($line): array => [
                'id' => (int) $line->getKey(),
                'product_id' => (int) $line->product_id,
                'stock_location_id' => (int) $line->stock_location_id,
                'unit' => $line->unit,
                'on_hand_delta' => (string) $line->on_hand_delta,
            ])->values()->all(),
        ];
    }
}
