<?php

namespace App\Actions\Lots;

use App\Actions\ManagementPlans\AssignFlockPlanAction;
use App\Enums\Lots\FlockStatus;
use App\Events\Lots\FlockRedistributed;
use App\Exceptions\Lots\LotsConflict;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\User;
use App\Queries\FarmStructure\LockPoultryHousesQuery;
use App\Services\Lots\FlockActivityJournal;
use App\Services\Lots\FlockState;
use App\Services\Lots\LotsHistory;
use App\Services\Lots\LotsSnapshots;
use App\Services\Lots\RunLotsCommand;
use App\Services\ManagementPlans\PlanActivityLinker;
use Illuminate\Support\Str;

final readonly class RedistributeFlockAction
{
    public function __construct(
        private RunLotsCommand $commands,
        private FlockState $state,
        private LockPoultryHousesQuery $houses,
        private LotsSnapshots $snapshots,
        private LotsHistory $history,
        private FlockActivityJournal $activities,
        private PlanActivityLinker $planActivities,
        private AssignFlockPlanAction $plans,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(Flock $flock, array $data, User $actor, string $source = 'api'): FlockOperation
    {
        if (isset($data['destination_code'])) {
            $data['destination_code'] = Str::upper(trim($data['destination_code']));
        }

        return $this->commands->execute($actor, 'flocks.redistribute', 'flock.redistribute', $data['idempotency_key'], ['flock' => $flock->public_id, ...$data], function (string $operationId) use ($flock, $data, $actor, $source): array {
            $receiverId = $data['destination_flock_id'] ?? null;
            if ($receiverId === $flock->public_id) {
                throw new LotsConflict('El lote receptor debe ser diferente del origen.');
            }
            $locked = $this->state->lock(array_values(array_filter([$flock->public_id, $receiverId])));
            $from = $locked->get($flock->public_id);
            $to = $receiverId === null ? null : $locked->get($receiverId);
            $this->state->version($from, (int) $data['version']);
            $this->state->open($from, true);
            $planActivity = $this->planActivities->resolve($from, $data['plan_activity_id'] ?? null, 'flock_movement');
            $quantity = (int) $data['quantity'];
            $this->state->positive($quantity);
            if ($quantity > $from->current_quantity) {
                throw new LotsConflict('La cantidad supera las aves vivas del lote origen.');
            }
            $time = $this->state->time($from, $data['occurred_at'] ?? null);
            $total = $quantity === $from->current_quantity;

            if ($to !== null) {
                $this->state->version($to, (int) $data['destination_version']);
                $this->state->open($to, true);
                $this->state->time($to, $time->toIso8601String());
                if ($from->breed_id !== $to->breed_id) {
                    throw new LotsConflict('La incorporación a otro lote debe ser de la misma raza.');
                }
            } elseif (! $total && empty($data['destination_code'])) {
                throw new LotsConflict('Indica el código del lote nuevo para la redistribución parcial.');
            }

            $destinationHouseId = $to === null ? (int) $data['destination_poultry_house_id'] : $to->poultry_house_id;
            if ($total && $to === null && $destinationHouseId === $from->poultry_house_id) {
                throw new LotsConflict('El traslado total requiere otro galpón.');
            }
            if ($total && $to === null && (isset($data['destination_code']) || isset($data['destination_public_id']))) {
                throw new LotsConflict('El traslado total conserva el lote y no admite datos de un lote nuevo.');
            }
            if ($total && $to !== null && isset($data['destination_code'])) {
                throw new LotsConflict('La unión total conserva los datos del lote receptor.');
            }

            $houses = $this->houses->execute([$from->poultry_house_id, $destinationHouseId]);
            $destinationHouse = $houses[$destinationHouseId];
            $this->state->receive(
                $destinationHouse,
                $destinationHouseId === $from->poultry_house_id ? 0 : $quantity,
                requiresEmpty: $to === null,
                existingReceiver: $to,
            );

            $before = [$from->public_id => $this->snapshots->flock($from)];
            if ($to !== null) {
                $before[$to->public_id] = $this->snapshots->flock($to);
            }

            if ($total && $to === null) {
                $type = 'total';
                $from->forceFill([
                    'poultry_house_id' => $destinationHouseId,
                    'production_unit_id' => $destinationHouse->productionUnitId,
                    'version' => $from->version + 1,
                ])->save();
                $destination = $from;
            } elseif ($total) {
                $type = 'total_existing';
                $from->forceFill([
                    'current_quantity' => 0,
                    'status' => FlockStatus::Finished,
                    'finalized_at' => $time,
                    'finalization_reason' => $data['reason'] ?? 'Unión total con otro lote.',
                    'version' => $from->version + 1,
                ])->save();
                $to->current_quantity += $quantity;
                $to->version++;
                $to->save();
                $destination = $to;
            } else {
                $from->current_quantity -= $quantity;
                $from->version++;
                $from->save();
                if ($to === null) {
                    $type = 'partial_new';
                    $destination = new Flock;
                    $destination->forceFill([
                        'public_id' => $data['destination_public_id'] ?? (string) Str::ulid(),
                        'code' => $data['destination_code'],
                        'breed_id' => $from->breed_id,
                        'supplier_id' => $from->supplier_id,
                        'supplier_name' => $from->supplier_name,
                        'origin' => $from->origin,
                        'entry_date' => $from->entry_date,
                        'established_at' => $time,
                        'poultry_house_id' => $destinationHouseId,
                        'production_unit_id' => $destinationHouse->productionUnitId,
                        'initial_quantity' => $quantity,
                        'current_quantity' => $quantity,
                        'status' => FlockStatus::Active,
                        'version' => 1,
                    ])->save();
                    $this->plans->inheritFuture($from, $destination, $time, $actor, $operationId, $source);
                } else {
                    $type = 'partial_existing';
                    $destination = $to;
                    $destination->current_quantity += $quantity;
                    $destination->version++;
                    $destination->save();
                }
            }

            $after = [$from->public_id => $this->snapshots->flock($from)];
            if ($destination->public_id !== $from->public_id) {
                $after[$destination->public_id] = $this->snapshots->flock($destination);
            }
            $movement = $this->history->movement($operationId, $type, $from, $destination, $quantity, $before, $after, $time, $actor, $data['reason'] ?? null, planActivityId: $planActivity?->id);
            $changedFlocks = [$from->public_id => $from];
            if ($destination->public_id !== $from->public_id) {
                $changedFlocks[$destination->public_id] = $destination;
            }
            foreach ($changedFlocks as $id => $changed) {
                $this->history->audit($changed, $actor, 'flock_redistributed', $type === 'total_existing' ? 'Unión total de lotes' : 'Aves redistribuidas', $operationId, $before[$id] ?? [], $after[$id], $changed->production_unit_id, $source, $data['reason'] ?? null);
                $this->activities->record($changed, $operationId, $type, 'flock.redistribute');
            }
            event(new FlockRedistributed($operationId, array_keys($after), $actor->id));

            return ['flock' => $after[$from->public_id], 'destination' => $after[$destination->public_id], 'movement' => $this->snapshots->movement($movement)];
        });
    }
}
