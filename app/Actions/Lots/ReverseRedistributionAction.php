<?php

namespace App\Actions\Lots;

use App\Enums\Lots\FlockStatus;
use App\Events\Lots\FlockRedistributed;
use App\Models\Lots\FlockMovement;
use App\Models\Lots\FlockOperation;
use App\Models\User;
use App\Services\Lots\FlockActivityJournal;
use App\Services\Lots\FlockState;
use App\Services\Lots\LotsHistory;
use App\Services\Lots\LotsSnapshots;
use App\Services\Lots\RedistributionReversal;
use App\Services\Lots\RunLotsCommand;

final readonly class ReverseRedistributionAction
{
    public function __construct(
        private RunLotsCommand $commands,
        private FlockState $state,
        private RedistributionReversal $reversal,
        private LotsSnapshots $snapshots,
        private LotsHistory $history,
        private FlockActivityJournal $activities,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(FlockMovement $movement, array $data, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute($actor, 'can_redistribute_flocks', 'flock.redistribution.reverse', $data['idempotency_key'], ['movement' => $movement->public_id, ...$data], function (string $operationId) use ($movement, $data, $actor, $source): array {
            ['flocks' => $flocks, 'source' => $originalSource, 'destination' => $originalDestination] = $this->reversal->prepare($movement, lock: true);
            $this->state->version($originalSource, (int) $data['version']);
            if ($originalDestination->id !== $originalSource->id) {
                $this->state->version($originalDestination, (int) ($data['destination_version'] ?? 0));
            }
            $time = $this->state->time($originalSource);
            $before = [];
            $after = [];
            foreach ($flocks as $id => $flock) {
                $before[$id] = $this->snapshots->flock($flock);
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
