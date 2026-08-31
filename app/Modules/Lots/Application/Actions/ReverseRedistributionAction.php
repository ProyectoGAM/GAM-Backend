<?php

namespace App\Modules\Lots\Application\Actions;

use App\Models\Lots\FlockMovement;
use App\Models\Lots\FlockOperation;
use App\Models\User;
use App\Modules\FarmStructure\Application\PublicApi\Queries\LockPoultryHousesQuery;
use App\Modules\Lots\Application\Services\FlockState;
use App\Modules\Lots\Application\Services\LotsHistory;
use App\Modules\Lots\Application\Services\LotsSnapshots;
use App\Modules\Lots\Application\Services\RunLotsCommand;
use App\Modules\Lots\Domain\Enums\FlockStatus;
use App\Modules\Lots\Domain\Events\FlockRedistributed;
use App\Modules\Lots\Domain\Exceptions\LotsConflict;

final readonly class ReverseRedistributionAction
{
    public function __construct(private RunLotsCommand $commands, private FlockState $state, private LockPoultryHousesQuery $houses, private LotsSnapshots $snapshots, private LotsHistory $history) {}

    /** @param array<string, mixed> $data */
    public function execute(FlockMovement $movement, array $data, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute($actor, 'flocks.redistribute', 'flock.redistribution.reverse', $data['idempotency_key'], ['movement' => $movement->public_id, ...$data], function (string $operationId) use ($movement, $data, $actor, $source): array {
            if (! in_array($movement->type, ['partial_new', 'partial_existing', 'total'], true)) {
                throw new LotsConflict('Sólo se pueden revertir redistribuciones originales.');
            }
            $flocks = $this->state->lock(array_keys($movement->after));
            $before = [];
            $net = [];
            $houseIds = [];
            foreach ($flocks as $id => $flock) {
                $this->state->open($flock, true);
                if ($flock->version !== $movement->after[$id]['version']) {
                    throw new LotsConflict('La redistribución tiene operaciones posteriores y no puede revertirse.');
                }
                $before[$id] = $this->snapshots->flock($flock);
                $previous = $movement->before[$id] ?? null;
                $houseIds[] = $flock->poultry_house_id;
                $net[$flock->poultry_house_id] = ($net[$flock->poultry_house_id] ?? 0) - $flock->current_quantity;
                if ($previous !== null) {
                    $houseIds[] = $previous['poultry_house_id'];
                    $net[$previous['poultry_house_id']] = ($net[$previous['poultry_house_id']] ?? 0) + $previous['current_quantity'];
                }
            }
            $houses = $this->houses->execute($houseIds);
            foreach ($net as $houseId => $delta) {
                if ($delta > 0) {
                    $this->state->receive($houses[$houseId], $delta);
                }
            }
            $originalSource = $flocks->firstWhere('id', $movement->source_flock_id);
            $originalDestination = $flocks->firstWhere('id', $movement->destination_flock_id);
            $this->state->version($originalSource, (int) $data['version']);
            if ($originalDestination->id !== $originalSource->id) {
                $this->state->version($originalDestination, (int) ($data['destination_version'] ?? 0));
            }
            $time = $this->state->time($originalSource);
            $after = [];
            foreach ($flocks as $id => $flock) {
                $previous = $movement->before[$id] ?? null;
                if ($previous === null) {
                    $flock->forceFill(['current_quantity' => 0, 'status' => FlockStatus::Finished, 'finalized_at' => $time, 'finalization_reason' => $data['reason']]);
                } else {
                    $flock->forceFill(['current_quantity' => $previous['current_quantity'], 'poultry_house_id' => $previous['poultry_house_id'], 'production_unit_id' => $previous['production_unit_id']]);
                }
                $flock->version++;
                $flock->save();
                $after[$id] = $this->snapshots->flock($flock);
                $this->history->audit($flock, $actor, 'flock_redistribution_reversed', 'Redistribución revertida', $operationId, $before[$id], $after[$id], $flock->production_unit_id, $source, $data['reason']);
            }
            $reversal = $this->history->movement($operationId, 'redistribution_reversal', $originalDestination, $originalSource, $movement->quantity, $before, $after, $time, $actor, $data['reason'], $movement->id);
            event(new FlockRedistributed($operationId, array_keys($after), $actor->id));

            return ['flock' => $after[$originalSource->public_id], 'destination' => $after[$originalDestination->public_id], 'movement' => $this->snapshots->movement($reversal)];
        });
    }
}
