<?php

namespace App\Modules\Lots\Application\Actions;

use App\Models\FarmStructure\ProductionUnit;
use App\Models\Lots\EggCollection;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\User;
use App\Modules\FarmStructure\Application\PublicApi\Queries\LockPoultryHousesQuery;
use App\Modules\FarmStructure\Domain\Enums\ProductionUnitStatus;
use App\Modules\Inventory\Application\PublicApi\Actions\RecordEggStockTransactionAction;
use App\Modules\Lots\Application\Services\FlockState;
use App\Modules\Lots\Application\Services\LotsHistory;
use App\Modules\Lots\Application\Services\LotsSnapshots;
use App\Modules\Lots\Application\Services\RunLotsCommand;
use App\Modules\Lots\Domain\Events\EggsCollected;
use App\Modules\Lots\Domain\Exceptions\LotsConflict;
use Illuminate\Support\Str;

final readonly class RecordEggCollectionAction
{
    public function __construct(
        private RunLotsCommand $commands,
        private FlockState $state,
        private LockPoultryHousesQuery $houses,
        private RecordEggStockTransactionAction $stock,
        private LotsSnapshots $snapshots,
        private LotsHistory $history,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(Flock $flock, array $data, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute($actor, 'egg-collections.manage', 'eggs.record', $data['idempotency_key'], ['flock' => $flock->public_id, ...$data], function (string $operationId) use ($flock, $data, $actor, $source): array {
            $locked = $this->state->lock([$flock->public_id])->get($flock->public_id);
            $this->state->open($locked);
            $unit = ProductionUnit::query()->whereKey($locked->production_unit_id)->lockForUpdate()->firstOrFail();
            if ($unit->status !== ProductionUnitStatus::Active) {
                throw new LotsConflict('La unidad productiva debe estar operativa para registrar nuevas recolecciones.');
            }
            if ($locked->current_quantity === 0) {
                throw new LotsConflict('No se puede registrar producción de un lote sin aves.');
            }
            $quantity = (int) ($data['quantity'] ?? 0);
            if ($quantity < 1 || $quantity > 2147483647) {
                throw new LotsConflict('La cantidad debe ser un entero entre 1 y 2147483647.');
            }
            $time = $this->state->time($locked, $data['occurred_at'] ?? null);
            $this->houses->execute([$locked->poultry_house_id]);

            $record = new EggCollection;
            $record->forceFill([
                'public_id' => $data['public_id'] ?? (string) Str::ulid(),
                'flock_id' => $locked->id,
                'poultry_house_id' => $locked->poultry_house_id,
                'production_unit_id' => $locked->production_unit_id,
                'quantity' => $quantity,
                'occurred_at' => $time,
                'notes' => $data['notes'] ?? null,
                'status' => 'recorded',
                'version' => 1,
                'created_by' => $actor->id,
            ])->save();

            $stock = $this->stock->execute(
                $this->productionUnit($locked->production_unit_id),
                'collection_receipt',
                $record->quantity,
                $operationId,
                $actor,
                $time->toIso8601String(),
                'Recolección de huevos',
                $record->notes,
                'egg_collection',
                $record->public_id,
                source: $source,
            );

            $snapshot = $this->snapshots->collection($record, $locked);
            $snapshot['stock_transaction_id'] = $stock['transaction']->public_id;
            $this->history->audit($record, $actor, 'eggs_collected', 'Recolección de huevos registrada', $operationId, [], $snapshot, $record->production_unit_id, $source);
            event(new EggsCollected($operationId, [$locked->public_id], $actor->id));

            return ['flock' => $this->snapshots->flock($locked), 'collection' => $snapshot, 'stock_transaction' => $stock['transaction']->public_id];
        });
    }

    private function productionUnit(int $id): ProductionUnit
    {
        return ProductionUnit::query()->findOrFail($id);
    }
}
