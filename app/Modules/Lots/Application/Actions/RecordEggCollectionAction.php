<?php

namespace App\Modules\Lots\Application\Actions;

use App\Models\Lots\EggCollection;
use App\Models\Lots\EggCollectionLine;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\User;
use App\Modules\FarmStructure\Application\PublicApi\Queries\LockPoultryHousesQuery;
use App\Modules\Inventory\Application\PublicApi\Actions\RecordEggProductionAction;
use App\Modules\Inventory\Domain\Exceptions\InventoryConflict;
use App\Modules\Lots\Application\Services\EggCollectionRules;
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
        private RecordEggProductionAction $inventory,
        private EggCollectionRules $rules,
        private LotsSnapshots $snapshots,
        private LotsHistory $history,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(Flock $flock, array $data, User $actor, string $source = 'api'): FlockOperation
    {
        $normalized = $this->rules->normalize($data);
        $payload = [...$data, ...$normalized];

        return $this->commands->execute($actor, 'egg-collections.manage', 'eggs.record', $data['idempotency_key'], ['flock' => $flock->public_id, ...$payload], function (string $operationId) use ($flock, $normalized, $payload, $actor, $source): array {
            $locked = $this->state->lock([$flock->public_id])->get($flock->public_id);
            $this->state->version($locked, (int) $payload['version']);
            $this->state->open($locked);
            if ($locked->current_quantity === 0) {
                throw new LotsConflict('No se puede registrar producción de un lote sin aves.');
            }
            $time = $this->state->time($locked, $payload['occurred_at'] ?? null);
            $this->houses->execute([$locked->poultry_house_id]);

            $record = new EggCollection;
            $record->forceFill([
                'public_id' => $payload['public_id'] ?? (string) Str::ulid(),
                'flock_id' => $locked->id,
                'poultry_house_id' => $locked->poultry_house_id,
                'production_unit_id' => $locked->production_unit_id,
                'product_id' => $normalized['lines'][0]['product_id'] ?? null,
                'stock_location_id' => $normalized['lines'][0]['stock_location_id'] ?? null,
                'inventory_movement_id' => null,
                'collected_quantity' => $normalized['collected_quantity'],
                'quantity' => $normalized['collected_quantity'],
                'discarded_quantity' => $normalized['discarded_quantity'],
                'discard_reason' => $normalized['discard_reason'],
                'occurred_at' => $time,
                'notes' => $payload['notes'] ?? null,
                'status' => 'recorded',
                'version' => 1,
                'created_by' => $actor->id,
            ])->save();

            foreach ($normalized['lines'] as $line) {
                EggCollectionLine::query()->create([
                    'egg_collection_id' => $record->id,
                    'product_id' => $line['product_id'],
                    'stock_location_id' => $line['stock_location_id'],
                    'quantity' => $line['quantity'],
                ]);
            }
            $record->load('lines');

            try {
                $movement = $this->inventory->executeLines(
                    array_map(static fn (array $line): array => [...$line, 'on_hand_delta' => (string) $line['quantity']], $normalized['lines']),
                    $record->public_id,
                    $operationId,
                    $time->toIso8601String(),
                    $actor,
                    'Recolección de huevos',
                    $source,
                );
            } catch (InventoryConflict $exception) {
                throw new LotsConflict($exception->getMessage(), previous: $exception);
            }

            $record->forceFill(['inventory_movement_id' => $movement?->id])->save();

            $locked->version++;
            $locked->save();
            $snapshot = $this->snapshots->collection($record, $locked);
            $this->history->audit($record, $actor, 'eggs_collected', 'Recolección de huevos registrada', $operationId, [], $snapshot, $record->production_unit_id, $source);
            event(new EggsCollected($operationId, [$locked->public_id], $actor->id));

            return ['flock' => $this->snapshots->flock($locked), 'collection' => $snapshot, 'inventory_movement_id' => $movement?->id];
        });
    }
}
