<?php

namespace App\Services\Lots;

use App\Enums\Lots\FlockStatus;
use App\Exceptions\Lots\LotsConflict;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockMovement;
use App\Queries\FarmStructure\LockPoultryHousesQuery;
use Illuminate\Database\Eloquent\Collection;

final readonly class RedistributionReversal
{
    public const ORIGINAL_TYPES = ['partial_new', 'partial_existing', 'total', 'total_existing'];

    public function __construct(
        private FlockState $state,
        private LockPoultryHousesQuery $houses,
        private FlockActivityJournal $activities,
    ) {}

    /**
     * Evalúa el movimiento con los mismos datos en la consulta y en el comando.
     *
     * @return array{flocks: Collection<string, Flock>, source: Flock, destination: Flock}
     */
    public function prepare(FlockMovement $movement, bool $lock = false): array
    {
        if ($movement->reverses_movement_id !== null || ! in_array($movement->type, self::ORIGINAL_TYPES, true)) {
            throw new LotsConflict('Sólo se pueden revertir redistribuciones originales.');
        }
        if (! $movement->reversal_verified) {
            throw new LotsConflict('No se pudo comprobar de forma completa la actividad histórica de la redistribución.');
        }

        $ids = array_keys($movement->after);
        $flocks = $lock ? $this->state->lock($ids) : $this->state->read($ids);
        if (FlockMovement::query()->where('reverses_movement_id', $movement->id)->exists()) {
            throw new LotsConflict('La redistribución ya fue revertida.');
        }

        $source = $flocks->firstWhere('id', $movement->source_flock_id);
        $destination = $flocks->firstWhere('id', $movement->destination_flock_id);
        $sameFlock = $movement->type === 'total';
        if ($source === null || $destination === null || $flocks->count() !== ($sameFlock ? 1 : 2)
            || ($source->id === $destination->id) !== $sameFlock
            || ! isset($movement->before[$source->public_id])
            || ($movement->type !== 'partial_new' && ! isset($movement->before[$destination->public_id]))) {
            throw new LotsConflict('La redistribución no conserva una referencia verificable a los lotes originales.');
        }
        if ($source->production_unit_id !== $destination->production_unit_id) {
            throw new LotsConflict('No se permiten movimientos de lotes entre unidades productivas.');
        }

        $net = [];
        $houseIds = [];
        foreach ($flocks as $id => $flock) {
            if (! $this->activities->isLatestFor($flock, $movement->operation_id)
                || $flock->version !== (int) ($movement->after[$id]['version'] ?? 0)) {
                throw new LotsConflict('La redistribución tiene operaciones posteriores y no puede revertirse.');
            }
            if ($flock->status === FlockStatus::Finished && $movement->type !== 'total_existing') {
                throw new LotsConflict('El estado actual del lote impide revertir la redistribución.');
            }
            $previous = $movement->before[$id] ?? null;
            $houseIds[] = $flock->poultry_house_id;
            $net[$flock->poultry_house_id] = ($net[$flock->poultry_house_id] ?? 0) - $flock->current_quantity;
            if ($previous !== null) {
                if (! isset($previous['production_unit_id'], $previous['poultry_house_id'], $previous['current_quantity'], $previous['status'])) {
                    throw new LotsConflict('La redistribución no conserva la fotografía original de los lotes.');
                }
                if ((int) $previous['production_unit_id'] !== $flock->production_unit_id) {
                    throw new LotsConflict('No se permiten movimientos de lotes entre unidades productivas.');
                }
                $houseIds[] = (int) $previous['poultry_house_id'];
                $net[$previous['poultry_house_id']] = ($net[$previous['poultry_house_id']] ?? 0) + (int) $previous['current_quantity'];
            }
        }

        $houses = $lock ? $this->houses->execute($houseIds) : $this->houses->read($houseIds);
        foreach ($houses as $house) {
            if ($house->productionUnitId !== $source->production_unit_id) {
                throw new LotsConflict('No se permiten movimientos de lotes entre unidades productivas.');
            }
        }
        foreach ($net as $houseId => $delta) {
            if ($delta <= 0) {
                continue;
            }
            $current = $flocks->first(fn (Flock $flock): bool => $flock->poultry_house_id === (int) $houseId);
            $this->state->receive(
                $houses[$houseId],
                requiresEmpty: $current?->status === FlockStatus::Finished || $current === null,
                existingReceiver: $current !== null && $current->status !== FlockStatus::Finished ? $current : null,
            );
        }

        return ['flocks' => $flocks, 'source' => $source, 'destination' => $destination];
    }
}
