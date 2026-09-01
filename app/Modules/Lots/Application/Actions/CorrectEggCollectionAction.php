<?php

namespace App\Modules\Lots\Application\Actions;

use App\Models\Lots\EggCollection;
use App\Models\Lots\EggCollectionLine;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\User;
use App\Modules\FarmStructure\Application\PublicApi\Queries\LockPoultryHousesQuery;
use App\Modules\Inventory\Application\PublicApi\Actions\RecordEggProductionAction;
use App\Modules\Inventory\Domain\Enums\InventoryMovementType;
use App\Modules\Inventory\Domain\Exceptions\InventoryConflict;
use App\Modules\Lots\Application\Services\EggCollectionRules;
use App\Modules\Lots\Application\Services\FlockState;
use App\Modules\Lots\Application\Services\LotsHistory;
use App\Modules\Lots\Application\Services\LotsSnapshots;
use App\Modules\Lots\Application\Services\RunLotsCommand;
use App\Modules\Lots\Domain\Events\EggCollectionCorrected;
use App\Modules\Lots\Domain\Exceptions\LotsConflict;

final readonly class CorrectEggCollectionAction
{
    public function __construct(
        private RunLotsCommand $commands,
        private FlockState $state,
        private LockPoultryHousesQuery $houses,
        private RecordEggProductionAction $inventory,
        private EggCollectionRules $rules,
        private LotsSnapshots $snapshots,
        private LotsHistory $history,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(EggCollection $record, array $data, User $actor, bool $cancel = false, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute($actor, 'egg-collections.manage', $cancel ? 'eggs.cancel' : 'eggs.correct', $data['idempotency_key'], ['record' => $record->public_id, ...$data], function (string $operationId) use ($record, $data, $actor, $cancel, $source): array {
            $flockId = Flock::query()->whereKey($record->flock_id)->value('public_id');
            $flock = $this->state->lock([$flockId])->get($flockId);
            $current = EggCollection::query()->whereKey($record->id)->lockForUpdate()->firstOrFail()->load(['lines', 'inventoryLosses.lines']);
            $this->state->version($flock, (int) $data['flock_version']);
            $this->state->open($flock);
            if ($current->version !== (int) $data['version'] || $current->status !== 'recorded') {
                throw new LotsConflict('La recolección cambió de versión o ya fue cancelada.');
            }
            $activeLosses = $this->activeLosses($current);
            if ($cancel && $activeLosses !== []) {
                throw new LotsConflict('No se puede cancelar una recolección con pérdidas posteriores activas.');
            }

            $desired = $this->desiredData($current, $data, $cancel);
            $normalized = $cancel ? [
                'collected_quantity' => $current->collected_quantity,
                'discarded_quantity' => $current->discarded_quantity,
                'discard_reason' => $current->discard_reason,
                'lines' => [],
            ] : $this->rules->normalize($desired);
            $desiredQuantities = [];
            foreach ($normalized['lines'] as $line) {
                $desiredQuantities[$line['product_id'].':'.$line['stock_location_id']] = $line['quantity'];
            }
            foreach ($activeLosses as $key => $lossQuantity) {
                if ($lossQuantity > ($desiredQuantities[$key] ?? 0)) {
                    throw new LotsConflict('La corrección no puede ser menor que las pérdidas posteriores ya registradas.');
                }
            }

            $this->houses->execute([$flock->poultry_house_id]);
            $time = $this->state->time($flock);
            $before = $this->snapshots->collection($current, $flock);
            $deltas = $this->lineDeltas($current->lines->all(), $normalized['lines']);
            try {
                $movement = $this->inventory->executeLines($deltas, $current->public_id, $operationId, $time->toIso8601String(), $actor, (string) $data['reason'], $source, InventoryMovementType::Adjustment);
            } catch (InventoryConflict $exception) {
                throw new LotsConflict($exception->getMessage(), previous: $exception);
            }

            $current->forceFill([
                'product_id' => $cancel ? $current->product_id : ($normalized['lines'][0]['product_id'] ?? null),
                'stock_location_id' => $cancel ? $current->stock_location_id : ($normalized['lines'][0]['stock_location_id'] ?? null),
                'inventory_movement_id' => $movement === null ? $current->inventory_movement_id : $movement->id,
                'collected_quantity' => $normalized['collected_quantity'],
                'quantity' => $normalized['collected_quantity'],
                'discarded_quantity' => $normalized['discarded_quantity'],
                'discard_reason' => $normalized['discarded_quantity'] === 0 ? null : $normalized['discard_reason'],
                'status' => $cancel ? 'cancelled' : 'recorded',
                'version' => $current->version + 1,
                ...(array_key_exists('notes', $data) && ! $cancel ? ['notes' => $data['notes']] : []),
            ])->save();
            if (! $cancel) {
                $current->lines()->delete();
                foreach ($normalized['lines'] as $line) {
                    EggCollectionLine::query()->create([
                        'egg_collection_id' => $current->id,
                        'product_id' => $line['product_id'],
                        'stock_location_id' => $line['stock_location_id'],
                        'quantity' => $line['quantity'],
                    ]);
                }
            }
            $flock->version++;
            $flock->save();
            $current->load('lines');
            $after = $this->snapshots->collection($current, $flock);
            $this->history->audit($current, $actor, $cancel ? 'egg_collection_cancelled' : 'egg_collection_corrected', $cancel ? 'Recolección cancelada' : 'Recolección rectificada', $operationId, $before, $after, $current->production_unit_id, $source, $data['reason']);
            event(new EggCollectionCorrected($operationId, [$flock->public_id], $actor->id));

            return ['flock' => $this->snapshots->flock($flock), 'collection' => $after, 'inventory_movement_id' => $movement?->id];
        });
    }

    /** @return array<string, int> */
    private function activeLosses(EggCollection $record): array
    {
        $losses = [];
        foreach ($record->inventoryLosses as $movement) {
            foreach ($movement->lines as $line) {
                $key = $line->product_id.':'.$line->stock_location_id;
                $losses[$key] = ($losses[$key] ?? 0) + abs((int) $line->on_hand_delta);
            }
        }

        return $losses;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function desiredData(EggCollection $current, array $data, bool $cancel): array
    {
        $lines = array_map(static fn (EggCollectionLine $line): array => [
            'product_id' => $line->product_id,
            'stock_location_id' => $line->stock_location_id,
            'quantity' => $line->quantity,
        ], $current->lines->all());
        if ($lines === [] && $current->product_id !== null && $current->stock_location_id !== null && $current->collected_quantity > $current->discarded_quantity) {
            $lines[] = [
                'product_id' => $current->product_id,
                'stock_location_id' => $current->stock_location_id,
                'quantity' => $current->collected_quantity - $current->discarded_quantity,
            ];
        }
        $collected = (int) ($data['collected_quantity'] ?? $data['quantity'] ?? $current->collected_quantity);
        $discarded = (int) ($data['discarded_quantity'] ?? $current->discarded_quantity);
        $quantityChanged = array_key_exists('collected_quantity', $data)
            || array_key_exists('discarded_quantity', $data)
            || array_key_exists('quantity', $data);
        if (! array_key_exists('lines', $data) && $quantityChanged && count($lines) === 1) {
            $usable = $collected - $discarded;
            if ($usable === 0) {
                $lines = [];
            } else {
                $lines[0]['quantity'] = $usable;
            }
        }

        return [
            'collected_quantity' => $collected,
            'discarded_quantity' => $discarded,
            'discard_reason' => $data['discard_reason'] ?? $current->discard_reason,
            'lines' => $data['lines'] ?? $lines,
            'quantity' => $cancel ? 0 : ($data['quantity'] ?? null),
        ];
    }

    /** @param list<EggCollectionLine> $old @param list<array{product_id:int, stock_location_id:int, quantity:int}> $new @return list<array{product_id:int, stock_location_id:int, on_hand_delta:string}> */
    private function lineDeltas(array $old, array $new): array
    {
        $deltas = [];
        $oldMap = [];
        foreach ($old as $line) {
            $oldMap[$line->product_id.':'.$line->stock_location_id] = [
                'product_id' => $line->product_id,
                'stock_location_id' => $line->stock_location_id,
                'quantity' => $line->quantity,
            ];
        }
        $newMap = [];
        foreach ($new as $line) {
            $newMap[$line['product_id'].':'.$line['stock_location_id']] = $line;
        }
        foreach (array_unique([...array_keys($oldMap), ...array_keys($newMap)]) as $key) {
            $delta = ($newMap[$key]['quantity'] ?? 0) - ($oldMap[$key]['quantity'] ?? 0);
            if ($delta !== 0) {
                $source = $newMap[$key] ?? $oldMap[$key];
                $deltas[] = [
                    'product_id' => $source['product_id'],
                    'stock_location_id' => $source['stock_location_id'],
                    'on_hand_delta' => (string) $delta,
                ];
            }
        }
        usort($deltas, static fn (array $left, array $right): int => [$left['stock_location_id'], $left['product_id']] <=> [$right['stock_location_id'], $right['product_id']]);

        return $deltas;
    }
}
