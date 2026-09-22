<?php

namespace App\Actions\Lots;

use App\Enums\Lots\FlockStatus;
use App\Events\Lots\FlockRedistributed;
use App\Exceptions\Lots\LotsConflict;
use App\Models\Lots\FlockMovement;
use App\Models\Lots\FlockOperation;
use App\Models\User;
use App\Queries\FarmStructure\LockPoultryHousesQuery;
use App\Services\Lots\FlockActivityJournal;
use App\Services\Lots\FlockState;
use App\Services\Lots\LotsHistory;
use App\Services\Lots\LotsSnapshots;
use App\Services\Lots\RunLotsCommand;

final readonly class ReverseRedistributionAction
{
    public function __construct(
        private RunLotsCommand $commands,
        private FlockState $state,
        private LockPoultryHousesQuery $houses,
        private LotsSnapshots $snapshots,
        private LotsHistory $history,
        private FlockActivityJournal $activities,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(FlockMovement $movement, array $data, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute($actor, 'flocks.redistribute', 'flock.redistribution.reverse', $data['idempotency_key'], ['movement' => $movement->public_id, ...$data], function (string $operationId) use ($movement, $data, $actor, $source): array {
            if (! in_array($movement->type, ['partial_new', 'partial_existing', 'total', 'total_existing'], true)) {
                throw new LotsConflict('Sólo se pueden revertir redistribuciones originales.');
            }
            if (! $movement->reversal_verified) {
                throw new LotsConflict('No se pudo comprobar de forma completa la actividad histórica de la redistribución.');
            }
            if (FlockMovement::query()->where('reverses_movement_id', $movement->id)->exists()) {
                throw new LotsConflict('La redistribución ya fue revertida.');
            }

            $flocks = $this->state->lock(array_keys($movement->after));
            $before = [];
            $net = [];
            $houseIds = [];
            foreach ($flocks as $id => $flock) {
                if (! $this->activities->isLatestFor($flock, $movement->operation_id)) {
                    throw new LotsConflict('La redistribución tiene operaciones posteriores y no puede revertirse.');
                }
                if ($flock->version !== (int) ($movement->after[$id]['version'] ?? 0)) {
                    throw new LotsConflict('La redistribución tiene operaciones posteriores y no puede revertirse.');
                }
                if ($flock->status === FlockStatus::Finished && $movement->type !== 'total_existing') {
                    throw new LotsConflict('El estado actual del lote impide revertir la redistribución.');
                }
                $before[$id] = $this->snapshots->flock($flock);
                $previous = $movement->before[$id] ?? null;
                $houseIds[] = $flock->poultry_house_id;
                $net[$flock->poultry_house_id] = ($net[$flock->poultry_house_id] ?? 0) - $flock->current_quantity;
                if ($previous !== null) {
                    $houseIds[] = (int) $previous['poultry_house_id'];
                    $net[$previous['poultry_house_id']] = ($net[$previous['poultry_house_id']] ?? 0) + (int) $previous['current_quantity'];
                }
            }

            $houses = $this->houses->execute($houseIds);
            foreach ($net as $houseId => $delta) {
                if ($delta <= 0) {
                    continue;
                }
                $current = $flocks->first(fn ($flock): bool => $flock->poultry_house_id === (int) $houseId);
                $this->state->receive(
                    $houses[$houseId],
                    $delta,
                    requiresEmpty: $current?->status === FlockStatus::Finished || $current === null,
                    existingReceiver: $current !== null && $current->status !== FlockStatus::Finished ? $current : null,
                );
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
                    $flock->forceFill([
                        'current_quantity' => 0,
                        'status' => FlockStatus::Finished,
                        'finalized_at' => $time,
                        'finalization_reason' => $data['reason'],
                    ]);
                } else {
                    $flock->forceFill([
                        'current_quantity' => (int) $previous['current_quantity'],
                        'status' => $previous['status'],
                        'poultry_house_id' => (int) $previous['poultry_house_id'],
                        'production_unit_id' => (int) $previous['production_unit_id'],
                        'finalized_at' => $previous['finalized_at'] ?? null,
                        'finalization_reason' => $previous['finalization_reason'] ?? null,
                    ]);
                }
                $flock->version++;
                $flock->save();
                $after[$id] = $this->snapshots->flock($flock);
                $this->history->audit($flock, $actor, 'flock_redistribution_reversed', 'Redistribución revertida', $operationId, $before[$id], $after[$id], $flock->production_unit_id, $source, $data['reason']);
                $this->activities->record($flock, $operationId, 'redistribution_reversal', 'flock.redistribution.reverse');
            }
            $reversal = $this->history->movement($operationId, 'redistribution_reversal', $originalDestination, $originalSource, $movement->quantity, $before, $after, $time, $actor, $data['reason'], $movement->id);
            event(new FlockRedistributed($operationId, array_keys($after), $actor->id));

            return ['flock' => $after[$originalSource->public_id], 'destination' => $after[$originalDestination->public_id], 'movement' => $this->snapshots->movement($reversal)];
        });
    }
}
