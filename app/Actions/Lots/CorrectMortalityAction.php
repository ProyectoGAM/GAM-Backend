<?php

namespace App\Actions\Lots;

use App\Enums\Lots\FlockStatus;
use App\Events\Lots\MortalityCorrected;
use App\Exceptions\Lots\LotsConflict;
use App\Interfaces\Clock;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockMovement;
use App\Models\Lots\FlockOperation;
use App\Models\Lots\MortalityRecord;
use App\Models\User;
use App\Queries\FarmStructure\LockPoultryHousesQuery;
use App\Services\Lots\FlockActivityJournal;
use App\Services\Lots\FlockState;
use App\Services\Lots\LotsHistory;
use App\Services\Lots\LotsSnapshots;
use App\Services\Lots\RunLotsCommand;
use Carbon\CarbonImmutable;

final readonly class CorrectMortalityAction
{
    public function __construct(
        private RunLotsCommand $commands,
        private FlockState $state,
        private LockPoultryHousesQuery $houses,
        private LotsSnapshots $snapshots,
        private LotsHistory $history,
        private FlockActivityJournal $activities,
        private Clock $clock,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(MortalityRecord $record, array $data, User $actor, bool $cancel = false, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute($actor, 'mortality.manage', $cancel ? 'mortality.cancel' : 'mortality.correct', $data['idempotency_key'], ['record' => $record->public_id, ...$data], function (string $operationId) use ($record, $data, $actor, $cancel, $source): array {
            $flockId = Flock::query()->whereKey($record->flock_id)->value('public_id');
            $flock = $this->state->lock([$flockId])->get($flockId);
            $current = MortalityRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $this->state->version($flock, (int) $data['flock_version']);
            if ($current->version !== (int) $data['version'] || $current->status !== 'recorded') {
                throw new LotsConflict('La mortalidad cambió de versión o ya fue cancelada.');
            }

            $quantity = $cancel ? 0 : (int) ($data['quantity'] ?? $current->quantity);
            if (! $cancel) {
                $this->state->positive($quantity);
            }
            $delta = $current->quantity - $quantity;
            $resultingQuantity = $flock->current_quantity + $delta;
            if (! $cancel && array_key_exists('notes', $data)
                && (! is_string($data['notes']) || trim($data['notes']) === '')) {
                throw new LotsConflict('Debes indicar el motivo de la mortalidad.');
            }
            $houses = $this->houses->execute([$flock->poultry_house_id]);
            $reactivationSnapshot = null;
            if ($flock->status === FlockStatus::Finished && $delta !== 0) {
                if ($resultingQuantity < 0) {
                    throw new LotsConflict('No se puede dejar un saldo negativo en un lote finalizado.');
                }
                $terminalSnapshot = $this->mortalityTerminalSnapshot($flock);
                if ($terminalSnapshot === null) {
                    throw new LotsConflict('El lote finalizado no puede reactivarse mediante esta corrección. Primero revierte la operación terminal correspondiente.');
                }
                if ($resultingQuantity > 0) {
                    if (! (bool) ($data['confirm_reactivation'] ?? false)) {
                        throw new LotsConflict('Debes confirmar la reactivación del lote finalizado antes de devolverle aves.');
                    }
                    $reactivationSnapshot = $terminalSnapshot;
                    $this->state->receive($houses[$flock->poultry_house_id], requiresEmpty: true);
                }
            } elseif (max(0, $resultingQuantity) > max(0, $flock->current_quantity)) {
                $this->state->open($flock);
                $this->state->receive($houses[$flock->poultry_house_id], existingReceiver: $flock);
            } elseif ($flock->status !== FlockStatus::Finished) {
                $this->state->open($flock);
            }

            $before = $this->snapshots->flock($flock);
            $oldRecord = $this->snapshots->mortality($current, $flock);
            if (! $cancel) {
                $current->quantity = $quantity;
                if (isset($data['occurred_at'])) {
                    $current->occurred_at = $this->correctedOccurrence($flock, $current, $data['occurred_at']);
                }
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
            if ($reactivationSnapshot !== null) {
                $flock->status = FlockStatus::from((string) $reactivationSnapshot['status']);
                $flock->finalized_at = null;
                $flock->finalization_reason = null;
            }
            $flock->version++;
            $flock->save();
            $after = $this->snapshots->flock($flock);
            $movement = $this->history->movement($operationId, 'mortality_correction', $delta <= 0 ? $flock : null, $delta > 0 ? $flock : null, abs($delta), [$flock->public_id => $before], [$flock->public_id => $after], $time, $actor, $data['reason']);
            $snapshot = $this->snapshots->mortality($current, $flock);
            $this->history->audit($current, $actor, $cancel ? 'mortality_cancelled' : 'mortality_corrected', 'Mortalidad rectificada', $operationId, $oldRecord, $snapshot, $current->production_unit_id, $source, $data['reason']);
            $this->history->audit($flock, $actor, 'flock_mortality_corrected', 'Cantidad viva corregida', $operationId, $before, $after, $flock->production_unit_id, $source, $data['reason']);
            $this->activities->record($flock, $operationId, $cancel ? 'mortality_cancelled' : 'mortality_correction', $cancel ? 'mortality.cancel' : 'mortality.correct');
            event(new MortalityCorrected($operationId, [$flock->public_id], $actor->id));

            return ['flock' => $after, 'mortality' => $snapshot, 'movement' => $this->snapshots->movement($movement)];
        });
    }

    /** @return array<string, mixed>|null */
    private function mortalityTerminalSnapshot(Flock $flock): ?array
    {
        $movements = FlockMovement::query()
            ->where(fn ($query) => $query->where('source_flock_id', $flock->id)->orWhere('destination_flock_id', $flock->id))
            ->orderByDesc('id')
            ->get();
        foreach ($movements as $movement) {
            if (! in_array($movement->type, ['mortality', 'mortality_correction'], true)) {
                continue;
            }
            $before = $movement->before[$flock->public_id] ?? null;
            $after = $movement->after[$flock->public_id] ?? null;
            if (! is_array($before)
                || ! is_array($after)
                || ! in_array($before['status'] ?? null, [FlockStatus::Active->value, FlockStatus::Quarantined->value], true)
                || ($after['status'] ?? null) !== FlockStatus::Finished->value
                || (int) ($before['current_quantity'] ?? 0) <= 0
                || (int) ($after['current_quantity'] ?? -1) !== 0) {
                continue;
            }
            if (! $this->activities->hasNoUnmarkedActivityAfter($flock, $movement->operation_id)
                || ! $this->activities->hasOnlyNeutralMortalityCorrectionsAfter($flock, $movement->operation_id)) {
                return null;
            }

            return $before;
        }

        return null;
    }

    /** Conserva el galpón histórico y limita la nueva fecha al mismo período de ubicación. */
    private function correctedOccurrence(Flock $flock, MortalityRecord $record, string $occurredAt): CarbonImmutable
    {
        $time = CarbonImmutable::parse($occurredAt)->utc();
        if ($time->greaterThan($this->clock->now()) || $time->lessThan($flock->established_at)) {
            throw new LotsConflict('La fecha debe pertenecer a la existencia del lote y no puede ser futura.');
        }

        $movements = FlockMovement::query()
            ->where(fn ($query) => $query->where('source_flock_id', $flock->id)->orWhere('destination_flock_id', $flock->id))
            ->whereNotIn('type', ['mortality', 'mortality_correction'])
            ->orderBy('occurred_at')->orderBy('id')->get();
        foreach ($movements as $movement) {
            $beforeHouse = $movement->before[$flock->public_id]['poultry_house_id'] ?? null;
            $afterHouse = $movement->after[$flock->public_id]['poultry_house_id'] ?? null;
            $isDeparture = in_array($movement->type, ['departure', 'total_existing'], true) && $movement->source_flock_id === $flock->id;
            if ($beforeHouse === $afterHouse && ! $isDeparture) {
                continue;
            }
            if (($movement->occurred_at->lessThanOrEqualTo($record->occurred_at) && $time->lessThan($movement->occurred_at))
                || ($movement->occurred_at->greaterThan($record->occurred_at) && $time->greaterThanOrEqualTo($movement->occurred_at))) {
                throw new LotsConflict('La fecha corregida debe conservar el período del galpón donde ocurrió la mortalidad.');
            }
        }

        return $time;
    }
}
