<?php

namespace App\Modules\Lots\Application\Actions;

use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\Lots\MortalityCategory;
use App\Models\Lots\MortalityRecord;
use App\Models\User;
use App\Modules\FarmStructure\Application\PublicApi\Queries\LockPoultryHousesQuery;
use App\Modules\Lots\Application\Services\FlockState;
use App\Modules\Lots\Application\Services\LotsHistory;
use App\Modules\Lots\Application\Services\LotsSnapshots;
use App\Modules\Lots\Application\Services\RunLotsCommand;
use App\Modules\Lots\Domain\Events\MortalityRecorded;
use App\Modules\Lots\Domain\Exceptions\LotsConflict;
use Illuminate\Support\Str;

final readonly class RecordMortalityAction
{
    public function __construct(private RunLotsCommand $commands, private FlockState $state, private LockPoultryHousesQuery $houses, private LotsSnapshots $snapshots, private LotsHistory $history) {}

    /** @param array<string, mixed> $data */
    public function execute(Flock $flock, array $data, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute($actor, 'mortality.manage', 'mortality.record', $data['idempotency_key'], ['flock' => $flock->public_id, ...$data], function (string $operationId) use ($flock, $data, $actor, $source): array {
            $locked = $this->state->lock([$flock->public_id])->get($flock->public_id);
            $this->state->version($locked, (int) $data['version']);
            $this->state->open($locked);
            $quantity = (int) $data['quantity'];
            $this->state->positive($quantity);
            if ($quantity > $locked->current_quantity) {
                throw new LotsConflict('La mortalidad supera las aves vivas del lote.');
            }
            $category = MortalityCategory::query()->sharedLock()->findOrFail($data['mortality_category_id']);
            if ($category->status !== 'active') {
                throw new LotsConflict('La categoría de mortalidad debe estar activa.');
            }
            $time = $this->state->time($locked, $data['occurred_at'] ?? null);
            $this->houses->execute([$locked->poultry_house_id]);
            $before = $this->snapshots->flock($locked);
            $record = new MortalityRecord;
            $record->forceFill([
                'public_id' => $data['public_id'] ?? (string) Str::ulid(), 'flock_id' => $locked->id,
                'poultry_house_id' => $locked->poultry_house_id, 'production_unit_id' => $locked->production_unit_id,
                'mortality_category_id' => $category->id, 'quantity' => $quantity, 'occurred_at' => $time,
                'notes' => $data['notes'] ?? null, 'status' => 'recorded', 'version' => 1, 'created_by' => $actor->id,
            ])->save();
            $locked->current_quantity -= $quantity;
            $locked->version++;
            $locked->save();
            $after = $this->snapshots->flock($locked);
            $movement = $this->history->movement($operationId, 'mortality', $locked, null, $quantity, [$locked->public_id => $before], [$locked->public_id => $after], $time, $actor);
            $snapshot = $this->snapshots->mortality($record, $locked);
            $this->history->audit($record, $actor, 'mortality_recorded', 'Mortalidad registrada', $operationId, [], $snapshot, $record->production_unit_id, $source);
            $this->history->audit($locked, $actor, 'flock_mortality_applied', 'Cantidad viva ajustada por mortalidad', $operationId, $before, $after, $locked->production_unit_id, $source);
            event(new MortalityRecorded($operationId, [$locked->public_id], $actor->id));

            return ['flock' => $after, 'mortality' => $snapshot, 'movement' => $this->snapshots->movement($movement)];
        });
    }
}
