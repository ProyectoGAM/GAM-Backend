<?php

namespace App\Modules\Lots\Application\Actions;

use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\User;
use App\Modules\Lots\Application\Services\FlockState;
use App\Modules\Lots\Application\Services\LotsHistory;
use App\Modules\Lots\Application\Services\LotsSnapshots;
use App\Modules\Lots\Application\Services\RunLotsCommand;
use App\Modules\Lots\Domain\Events\FlockUpdated;
use Illuminate\Support\Str;

final readonly class UpdateFlockAction
{
    public function __construct(private RunLotsCommand $commands, private FlockState $state, private LotsSnapshots $snapshots, private LotsHistory $history) {}

    /** @param array<string, mixed> $data */
    public function execute(Flock $flock, array $data, User $actor, string $source = 'api'): FlockOperation
    {
        if (isset($data['code'])) {
            $data['code'] = Str::upper(trim($data['code']));
        }

        return $this->commands->execute($actor, 'flocks.manage', 'flock.update', $data['idempotency_key'], ['flock' => $flock->public_id, ...$data], function (string $operationId) use ($flock, $data, $actor, $source): array {
            $locked = $this->state->lock([$flock->public_id])->get($flock->public_id);
            $this->state->version($locked, (int) $data['version']);
            $this->state->open($locked);
            $before = $this->snapshots->flock($locked);
            $locked->fill(array_intersect_key($data, array_flip(['code', 'notes'])));
            $locked->version++;
            $locked->save();
            $after = $this->snapshots->flock($locked);
            $this->history->audit($locked, $actor, 'flock_updated', 'Datos del lote modificados', $operationId, $before, $after, $locked->production_unit_id, $source);
            event(new FlockUpdated($operationId, [$locked->public_id], $actor->id));

            return ['flock' => $after];
        });
    }
}
