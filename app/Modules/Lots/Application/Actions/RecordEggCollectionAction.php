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
use App\Modules\Lots\Domain\Events\EggsCollected;
use App\Modules\Lots\Domain\Exceptions\LotsConflict;
use Illuminate\Support\Str;

final readonly class RecordEggCollectionAction
{
    public function __construct(private RunLotsCommand $commands, private FlockState $state, private LockPoultryHousesQuery $houses, private RecordEggProductionAction $inventory, private LotsSnapshots $snapshots, private LotsHistory $history) {}

    /** @param array<string, mixed> $data */
    public function execute(Flock $flock, array $data, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute($actor, 'egg-collections.manage', 'eggs.record', $data['idempotency_key'], ['flock' => $flock->public_id, ...$data], function (string $operationId) use ($flock, $data, $actor, $source): array {
            $locked = $this->state->lock([$flock->public_id])->get($flock->public_id);
            $this->state->version($locked, (int) $data['version']);
            $this->state->open($locked);
            if ($locked->current_quantity === 0) {
                throw new LotsConflict('No se puede registrar producción de un lote sin aves.');
            }
            $quantity = (int) $data['quantity'];
            $this->state->positive($quantity);
            $time = $this->state->time($locked, $data['occurred_at'] ?? null);
            $this->houses->execute([$locked->poultry_house_id]);
            $record = new EggCollection;
            $record->forceFill([
                'public_id' => $data['public_id'] ?? (string) Str::ulid(), 'flock_id' => $locked->id,
                'poultry_house_id' => $locked->poultry_house_id, 'production_unit_id' => $locked->production_unit_id,
                'product_id' => $data['product_id'], 'stock_location_id' => $data['stock_location_id'],
                'quantity' => $quantity, 'occurred_at' => $time, 'notes' => $data['notes'] ?? null,
                'status' => 'recorded', 'version' => 1, 'created_by' => $actor->id,
            ])->save();
            try {
                $movement = $this->inventory->execute($record->product_id, $record->stock_location_id, $quantity, $record->public_id, $operationId, $time->toIso8601String(), $actor, 'Recolección de huevos', $source);
            } catch (InventoryConflict $exception) {
                throw new LotsConflict($exception->getMessage(), previous: $exception);
            }
            $record->inventory_movement_id = $movement?->id;
            $record->save();
            $locked->version++;
            $locked->save();
            $snapshot = $this->snapshots->collection($record, $locked);
            $this->history->audit($record, $actor, 'eggs_collected', 'Recolección de huevos registrada', $operationId, [], $snapshot, $record->production_unit_id, $source);
            event(new EggsCollected($operationId, [$locked->public_id], $actor->id));

            return ['flock' => $this->snapshots->flock($locked), 'collection' => $snapshot];
        });
    }
}
