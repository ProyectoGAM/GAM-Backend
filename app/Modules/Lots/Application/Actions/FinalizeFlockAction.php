<?php

namespace App\Modules\Lots\Application\Actions;

use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\User;
use App\Modules\FarmStructure\Application\PublicApi\Queries\LockPoultryHousesQuery;
use App\Modules\Lots\Application\Services\FlockState;
use App\Modules\Lots\Application\Services\LotsHistory;
use App\Modules\Lots\Application\Services\LotsSnapshots;
use App\Modules\Lots\Application\Services\RunLotsCommand;
use App\Modules\Lots\Domain\Enums\FlockStatus;
use App\Modules\Lots\Domain\Events\FlockFinalized;

final readonly class FinalizeFlockAction
{
    public function __construct(private RunLotsCommand $commands, private FlockState $state, private LockPoultryHousesQuery $houses, private LotsSnapshots $snapshots, private LotsHistory $history) {}

    /** @param array<string, mixed> $data */
    public function execute(Flock $flock, array $data, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute($actor, 'flocks.finalize', 'flock.finalize', $data['idempotency_key'], ['flock' => $flock->public_id, ...$data], function (string $operationId) use ($flock, $data, $actor, $source): array {
            $locked = $this->state->lock([$flock->public_id])->get($flock->public_id);
            $this->state->version($locked, (int) $data['version']);
            $this->state->open($locked);
            $time = $this->state->time($locked);
            $this->houses->execute([$locked->poultry_house_id]);
            $before = $this->snapshots->flock($locked);
            $quantity = $locked->current_quantity;
            $locked->forceFill(['current_quantity' => 0, 'status' => FlockStatus::Finished, 'finalized_at' => $time, 'finalization_reason' => $data['reason'], 'version' => $locked->version + 1])->save();
            $after = $this->snapshots->flock($locked);
            $movement = $this->history->movement($operationId, 'departure', $locked, null, $quantity, [$locked->public_id => $before], [$locked->public_id => $after], $time, $actor, $data['reason']);
            $this->history->audit($locked, $actor, 'flock_finalized', 'Lote finalizado con egreso de aves', $operationId, $before, $after, $locked->production_unit_id, $source, $data['reason']);
            event(new FlockFinalized($operationId, [$locked->public_id], $actor->id));

            return ['flock' => $after, 'movement' => $this->snapshots->movement($movement)];
        });
    }
}
