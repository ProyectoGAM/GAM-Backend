<?php

namespace App\Modules\Lots\Application\Actions;

use App\Models\Inventory\InventoryMovement;
use App\Models\Lots\EggCollection;
use App\Models\Lots\FlockOperation;
use App\Models\User;
use App\Modules\Inventory\Application\Actions\RecordStockLossAction;
use App\Modules\Inventory\Domain\Exceptions\InventoryConflict;
use App\Modules\Lots\Application\Services\LotsHistory;
use App\Modules\Lots\Application\Services\LotsSnapshots;
use App\Modules\Lots\Application\Services\RunLotsCommand;
use App\Modules\Lots\Domain\Exceptions\LotsConflict;
use Carbon\CarbonImmutable;

final readonly class RecordEggCollectionLossAction
{
    public function __construct(
        private RunLotsCommand $commands,
        private RecordStockLossAction $inventory,
        private LotsSnapshots $snapshots,
        private LotsHistory $history,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(EggCollection $record, array $data, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute($actor, 'egg-collections.manage', 'eggs.loss.record', $data['idempotency_key'], ['record' => $record->public_id, ...$data], function (string $operationId) use ($record, $data, $actor, $source): array {
            $current = EggCollection::query()->whereKey($record->id)->lockForUpdate()->firstOrFail()->load(['flock', 'lines', 'inventoryLosses.lines']);
            if ($current->status !== 'recorded') {
                throw new LotsConflict('No se pueden registrar pérdidas sobre una recolección cancelada.');
            }
            $lossDate = isset($data['occurred_at']) ? CarbonImmutable::parse($data['occurred_at'])->utc() : now()->toImmutable();
            if ($lossDate->greaterThan(now()) || $lossDate->lessThan($current->occurred_at)) {
                throw new LotsConflict('La fecha de la pérdida debe pertenecer a la recolección y no puede ser futura.');
            }

            $available = [];
            foreach ($current->lines as $line) {
                $available[$line->product_id.':'.$line->stock_location_id] = $line->quantity;
            }
            foreach ($current->inventoryLosses as $movement) {
                foreach ($movement->lines as $line) {
                    $key = $line->product_id.':'.$line->stock_location_id;
                    $available[$key] = ($available[$key] ?? 0) - abs((int) $line->on_hand_delta);
                }
            }

            $lines = [];
            $keys = [];
            foreach (($data['lines'] ?? []) as $line) {
                $productId = (int) ($line['product_id'] ?? 0);
                $locationId = (int) ($line['stock_location_id'] ?? 0);
                $quantity = (int) ($line['quantity'] ?? 0);
                $key = $productId.':'.$locationId;
                if ($productId < 1 || $locationId < 1 || $quantity < 1 || isset($keys[$key])) {
                    throw new LotsConflict('Cada pérdida debe indicar una clasificación única y una cantidad positiva.');
                }
                if (! array_key_exists($key, $available) || $quantity > $available[$key]) {
                    throw new LotsConflict('La pérdida supera la cantidad utilizable pendiente de la clasificación.');
                }
                $keys[$key] = true;
                $lines[] = ['product_id' => $productId, 'stock_location_id' => $locationId, 'quantity' => $quantity];
            }
            if ($lines === []) {
                throw new LotsConflict('Debes indicar al menos una línea de pérdida.');
            }

            $before = $this->snapshots->collection($current, $current->flock);
            try {
                $movement = $this->inventory->execute([
                    'idempotency_key' => $data['idempotency_key'],
                    'operation_id' => $operationId,
                    'lines' => $lines,
                    'reason' => $data['reason'],
                    'occurred_at' => $lossDate->toIso8601String(),
                    'reference_type' => 'egg_collection',
                    'reference_id' => $current->public_id,
                ], $actor, $source);
            } catch (InventoryConflict $exception) {
                throw new LotsConflict($exception->getMessage(), previous: $exception);
            }
            $movement->load('lines');
            $after = $this->snapshots->collection($current->fresh()->load(['lines', 'inventoryLosses.lines']), $current->flock);
            $this->history->audit($current, $actor, 'egg_collection_loss_recorded', 'Pérdida posterior de huevos registrada', $operationId, $before, $after, $current->production_unit_id, $source, $data['reason']);

            return ['collection' => $after, 'loss' => $this->movementSnapshot($movement)];
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
