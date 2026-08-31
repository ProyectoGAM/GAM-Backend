<?php

namespace App\Modules\Lots\Application\Actions;

use App\Models\Lots\EggCollection;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\User;
use App\Modules\FarmStructure\Application\PublicApi\Queries\LockPoultryHousesQuery;
use App\Modules\Inventory\Application\PublicApi\Actions\RecordEggProductionAction;
use App\Modules\Inventory\Domain\Exceptions\InventoryConflict;
use App\Modules\Lots\Application\Services\FlockState;
use App\Modules\Lots\Application\Services\LotsHistory;
use App\Modules\Lots\Application\Services\LotsSnapshots;
use App\Modules\Lots\Application\Services\RunLotsCommand;
use App\Modules\Lots\Domain\Events\EggCollectionCorrected;
use App\Modules\Lots\Domain\Exceptions\LotsConflict;

final readonly class CorrectEggCollectionAction
{
    public function __construct(private RunLotsCommand $commands, private FlockState $state, private LockPoultryHousesQuery $houses, private RecordEggProductionAction $inventory, private LotsSnapshots $snapshots, private LotsHistory $history) {}

    /** @param array<string, mixed> $data */
    public function execute(EggCollection $record, array $data, User $actor, bool $cancel = false, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute($actor, 'egg-collections.manage', $cancel ? 'eggs.cancel' : 'eggs.correct', $data['idempotency_key'], ['record' => $record->public_id, ...$data], function (string $operationId) use ($record, $data, $actor, $cancel, $source): array {
            $flockId = Flock::query()->whereKey($record->flock_id)->value('public_id');
            $flock = $this->state->lock([$flockId])->get($flockId);
            $current = EggCollection::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $this->state->version($flock, (int) $data['flock_version']);
            $this->state->open($flock);
            if ($current->version !== (int) $data['version'] || $current->status !== 'recorded') {
                throw new LotsConflict('La recolección cambió de versión o ya fue cancelada.');
            }
            $quantity = $cancel ? 0 : (int) ($data['quantity'] ?? $current->quantity);
            if (! $cancel) {
                $this->state->positive($quantity);
            }
            $this->houses->execute([$flock->poultry_house_id]);
            $time = $this->state->time($flock);
            $before = $this->snapshots->collection($current, $flock);
            try {
                $movement = $this->inventory->execute($current->product_id, $current->stock_location_id, $quantity - $current->quantity, $current->public_id, $operationId, $time->toIso8601String(), $actor, $data['reason'], $source);
            } catch (InventoryConflict $exception) {
                throw new LotsConflict($exception->getMessage(), previous: $exception);
            }
            if ($cancel) {
                $current->status = 'cancelled';
            } else {
                $current->quantity = $quantity;
                if (array_key_exists('notes', $data)) {
                    $current->notes = $data['notes'];
                }
            }
            if ($movement !== null) {
                $current->inventory_movement_id = $movement->id;
            }
            $current->version++;
            $current->save();
            $flock->version++;
            $flock->save();
            $after = $this->snapshots->collection($current, $flock);
            $this->history->audit($current, $actor, $cancel ? 'egg_collection_cancelled' : 'egg_collection_corrected', 'Recolección rectificada', $operationId, $before, $after, $current->production_unit_id, $source, $data['reason']);
            event(new EggCollectionCorrected($operationId, [$flock->public_id], $actor->id));

            return ['flock' => $this->snapshots->flock($flock), 'collection' => $after];
        });
    }
}
