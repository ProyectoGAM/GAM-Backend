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
use App\Modules\Lots\Domain\Events\FlockRedistributed;
use App\Modules\Lots\Domain\Exceptions\LotsConflict;
use Illuminate\Support\Str;

final readonly class RedistributeFlockAction
{
    public function __construct(private RunLotsCommand $commands, private FlockState $state, private LockPoultryHousesQuery $houses, private LotsSnapshots $snapshots, private LotsHistory $history) {}

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
                if ($total || $from->breed_id !== $to->breed_id) {
                    throw new LotsConflict('La incorporación a otro lote debe ser parcial y de la misma raza.');
                }
            }
            $destinationHouseId = $to !== null ? $to->poultry_house_id : (int) $data['destination_poultry_house_id'];
            if ($total && $destinationHouseId === $from->poultry_house_id) {
                throw new LotsConflict('El traslado total requiere otro galpón.');
            }
            if ($total && (isset($data['destination_code']) || isset($data['destination_public_id']))) {
                throw new LotsConflict('El traslado total conserva el lote y no admite datos de un lote nuevo.');
            }
            if (! $total && $to === null && empty($data['destination_code'])) {
                throw new LotsConflict('Indica el código del lote nuevo para la redistribución parcial.');
            }
            $houses = $this->houses->execute([$from->poultry_house_id, $destinationHouseId]);
            $destinationHouse = $houses[$destinationHouseId];
            $this->state->receive($destinationHouse, $destinationHouseId === $from->poultry_house_id ? 0 : $quantity);
            $before = [$from->public_id => $this->snapshots->flock($from)];
            if ($to !== null) {
                $before[$to->public_id] = $this->snapshots->flock($to);
            }
            if ($total) {
                $type = 'total';
                $from->forceFill(['poultry_house_id' => $destinationHouseId, 'production_unit_id' => $destinationHouse->productionUnitId, 'version' => $from->version + 1])->save();
                $to = $from;
            } else {
                $from->current_quantity -= $quantity;
                $from->version++;
                $from->save();
                if ($to === null) {
                    $type = 'partial_new';
                    $to = new Flock;
                    $to->forceFill([
                        'public_id' => $data['destination_public_id'] ?? (string) Str::ulid(), 'code' => $data['destination_code'],
                        'breed_id' => $from->breed_id, 'supplier_id' => $from->supplier_id, 'supplier_name' => $from->supplier_name,
                        'origin' => $from->origin, 'entry_date' => $from->entry_date, 'established_at' => $time,
                        'poultry_house_id' => $destinationHouseId, 'production_unit_id' => $destinationHouse->productionUnitId,
                        'initial_quantity' => $quantity, 'current_quantity' => $quantity, 'status' => FlockStatus::Active, 'version' => 1,
                    ])->save();
                } else {
                    $type = 'partial_existing';
                    $to->current_quantity += $quantity;
                    $to->version++;
                    $to->save();
                }
            }
            $after = [$from->public_id => $this->snapshots->flock($from), $to->public_id => $this->snapshots->flock($to)];
            $movement = $this->history->movement($operationId, $type, $from, $to, $quantity, $before, $after, $time, $actor, $data['reason'] ?? null);
            foreach ([$from->public_id => $from, $to->public_id => $to] as $id => $changed) {
                $this->history->audit($changed, $actor, 'flock_redistributed', 'Aves redistribuidas', $operationId, $before[$id] ?? [], $after[$id], $changed->production_unit_id, $source, $data['reason'] ?? null);
            }
            event(new FlockRedistributed($operationId, array_keys($after), $actor->id));

            return ['flock' => $after[$from->public_id], 'destination' => $after[$to->public_id], 'movement' => $this->snapshots->movement($movement)];
        });
    }
}
