<?php

namespace App\Actions\Lots;

use App\Enums\Lots\FlockStatus;
use App\Events\Lots\FlockStatusChanged;
use App\Exceptions\Lots\LotsConflict;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\User;
use App\Services\Lots\FlockState;
use App\Services\Lots\LotsHistory;
use App\Services\Lots\LotsSnapshots;
use App\Services\Lots\RunLotsCommand;

final readonly class ChangeFlockStatusAction
{
    public function __construct(private RunLotsCommand $commands, private FlockState $state, private LotsSnapshots $snapshots, private LotsHistory $history) {}

    /** @param array<string, mixed> $data */
    public function execute(Flock $flock, array $data, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute($actor, 'flocks.manage', 'flock.status', $data['idempotency_key'], ['flock' => $flock->public_id, ...$data], function (string $operationId) use ($flock, $data, $actor, $source): array {
            $locked = $this->state->lock([$flock->public_id])->get($flock->public_id);
            $this->state->version($locked, (int) $data['version']);
            $status = FlockStatus::from($data['status']);
            if ($status === FlockStatus::Finished || ! $locked->status->canTransitionTo($status)) {
                throw new LotsConflict('La transición de estado del lote no está permitida.');
            }
            $before = $this->snapshots->flock($locked);
            $locked->status = $status;
            $locked->version++;
            $locked->save();
            $after = $this->snapshots->flock($locked);
            $this->history->audit($locked, $actor, 'flock_status_changed', 'Estado del lote cambiado', $operationId, $before, $after, $locked->production_unit_id, $source, $data['reason']);
            event(new FlockStatusChanged($operationId, [$locked->public_id], $actor->id));

            return ['flock' => $after];
        });
    }
}
