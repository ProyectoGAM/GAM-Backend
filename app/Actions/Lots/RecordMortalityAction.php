<?php

namespace App\Actions\Lots;

use App\Events\Lots\MortalityRecorded;
use App\Exceptions\Lots\LotsConflict;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\Lots\MortalityRecord;
use App\Models\User;
use App\Queries\FarmStructure\LockPoultryHousesQuery;
use App\Services\Lots\FlockActivityJournal;
use App\Services\Lots\FlockState;
use App\Services\Lots\LotsHistory;
use App\Services\Lots\LotsSnapshots;
use App\Services\Lots\RunLotsCommand;
use App\Services\ManagementPlans\PlanActivityLinker;
use Illuminate\Support\Str;

final readonly class RecordMortalityAction
{
    public function __construct(private RunLotsCommand $commands, private FlockState $state, private LockPoultryHousesQuery $houses, private LotsSnapshots $snapshots, private LotsHistory $history, private FlockActivityJournal $activities, private PlanActivityLinker $planActivities) {}

    /** @param array<string, mixed> $data */
    public function execute(Flock $flock, array $data, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute($actor, 'mortality.manage', 'mortality.record', $data['idempotency_key'], ['flock' => $flock->public_id, ...$data], function (string $operationId) use ($flock, $data, $actor, $source): array {
            $locked = $this->state->lock([$flock->public_id])->get($flock->public_id);
            $this->state->version($locked, (int) $data['version']);
            $this->state->open($locked);
            $quantity = (int) $data['quantity'];
            $this->state->positive($quantity);
            if (! is_string($data['notes'] ?? null) || trim($data['notes']) === '') {
                throw new LotsConflict('Debes indicar el motivo de la mortalidad.');
            }
            if (! is_string($data['occurred_at'] ?? null)) {
                throw new LotsConflict('Debes indicar la fecha de la mortalidad.');
            }
            $time = $this->state->time($locked, $data['occurred_at']);
            $planActivity = $this->planActivities->resolve($locked, $data['plan_activity_id'] ?? null, 'mortality');
            $this->houses->execute([$locked->poultry_house_id]);
            $before = $this->snapshots->flock($locked);
            $record = new MortalityRecord;
            $record->forceFill([
                'public_id' => $data['public_id'] ?? (string) Str::ulid(), 'flock_id' => $locked->id,
                'flock_plan_activity_id' => $planActivity?->id, 'operation_id' => $operationId,
                'poultry_house_id' => $locked->poultry_house_id, 'production_unit_id' => $locked->production_unit_id,
                'quantity' => $quantity, 'occurred_at' => $time,
                'notes' => $data['notes'], 'status' => 'recorded', 'version' => 1, 'created_by' => $actor->id,
            ])->save();
            $locked->current_quantity -= $quantity;
            $locked->version++;
            $locked->save();
            $after = $this->snapshots->flock($locked);
            $movement = $this->history->movement($operationId, 'mortality', $locked, null, $quantity, [$locked->public_id => $before], [$locked->public_id => $after], $time, $actor, planActivityId: $planActivity?->id);
            $snapshot = $this->snapshots->mortality($record, $locked);
            $this->history->audit($record, $actor, 'mortality_recorded', 'Mortalidad registrada', $operationId, [], $snapshot, $record->production_unit_id, $source);
            $this->history->audit($locked, $actor, 'flock_mortality_applied', 'Cantidad viva ajustada por mortalidad', $operationId, $before, $after, $locked->production_unit_id, $source);
            $this->activities->record($locked, $operationId, 'mortality', 'mortality.record');
            event(new MortalityRecorded($operationId, [$locked->public_id], $actor->id));

            return ['flock' => $after, 'mortality' => $snapshot, 'movement' => $this->snapshots->movement($movement)];
        });
    }
}
