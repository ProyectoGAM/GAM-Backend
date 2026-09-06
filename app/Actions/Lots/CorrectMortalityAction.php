<?php

namespace App\Actions\Lots;

use App\Events\Lots\MortalityCorrected;
use App\Exceptions\Lots\LotsConflict;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\Lots\MortalityCategory;
use App\Models\Lots\MortalityRecord;
use App\Models\User;
use App\Queries\FarmStructure\LockPoultryHousesQuery;
use App\Services\Lots\FlockState;
use App\Services\Lots\LotsHistory;
use App\Services\Lots\LotsSnapshots;
use App\Services\Lots\RunLotsCommand;

final readonly class CorrectMortalityAction
{
    public function __construct(private RunLotsCommand $commands, private FlockState $state, private LockPoultryHousesQuery $houses, private LotsSnapshots $snapshots, private LotsHistory $history) {}

    /** @param array<string, mixed> $data */
    public function execute(MortalityRecord $record, array $data, User $actor, bool $cancel = false, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute($actor, 'mortality.manage', $cancel ? 'mortality.cancel' : 'mortality.correct', $data['idempotency_key'], ['record' => $record->public_id, ...$data], function (string $operationId) use ($record, $data, $actor, $cancel, $source): array {
            $flockId = Flock::query()->whereKey($record->flock_id)->value('public_id');
            $flock = $this->state->lock([$flockId])->get($flockId);
            $current = MortalityRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $this->state->version($flock, (int) $data['flock_version']);
            $this->state->open($flock);
            if ($current->version !== (int) $data['version'] || $current->status !== 'recorded') {
                throw new LotsConflict('La mortalidad cambió de versión o ya fue cancelada.');
            }
            $quantity = $cancel ? 0 : (int) ($data['quantity'] ?? $current->quantity);
            if (! $cancel) {
                $this->state->positive($quantity);
            }
            $delta = $current->quantity - $quantity;
            if ($flock->current_quantity + $delta < 0) {
                throw new LotsConflict('La corrección dejaría una cantidad viva negativa.');
            }
            $houses = $this->houses->execute([$flock->poultry_house_id]);
            if ($delta > 0) {
                $this->state->receive($houses[$flock->poultry_house_id], $delta);
            }
            $before = $this->snapshots->flock($flock);
            $oldRecord = $this->snapshots->mortality($current, $flock);
            if (isset($data['mortality_category_id']) && (int) $data['mortality_category_id'] !== $current->mortality_category_id) {
                $category = MortalityCategory::query()->sharedLock()->findOrFail($data['mortality_category_id']);
                if ($category->status !== 'active') {
                    throw new LotsConflict('La categoría de mortalidad debe estar activa.');
                }
                $current->mortality_category_id = $category->id;
            }
            if (! $cancel) {
                $current->quantity = $quantity;
                if (array_key_exists('notes', $data)) {
                    $current->notes = $data['notes'];
                }
            } else {
                $current->status = 'cancelled';
            }
            $current->version++;
            $current->save();
            $time = $this->state->time($flock);
            $flock->current_quantity += $delta;
            $flock->version++;
            $flock->save();
            $after = $this->snapshots->flock($flock);
            $movement = $this->history->movement($operationId, 'mortality_correction', $delta <= 0 ? $flock : null, $delta > 0 ? $flock : null, abs($delta), [$flock->public_id => $before], [$flock->public_id => $after], $time, $actor, $data['reason']);
            $snapshot = $this->snapshots->mortality($current, $flock);
            $this->history->audit($current, $actor, $cancel ? 'mortality_cancelled' : 'mortality_corrected', 'Mortalidad rectificada', $operationId, $oldRecord, $snapshot, $current->production_unit_id, $source, $data['reason']);
            $this->history->audit($flock, $actor, 'flock_mortality_corrected', 'Cantidad viva corregida', $operationId, $before, $after, $flock->production_unit_id, $source, $data['reason']);
            event(new MortalityCorrected($operationId, [$flock->public_id], $actor->id));

            return ['flock' => $after, 'mortality' => $snapshot, 'movement' => $this->snapshots->movement($movement)];
        });
    }
}
