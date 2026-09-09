<?php

namespace App\Services\Lots;

use App\Exceptions\Lots\LotsConflict;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockMovement;
use Carbon\CarbonImmutable;

final readonly class WeighingFlockProjection
{
    /** @return array{flock_id: string, population: int, poultry_house_id: int, production_unit_id: int, entry_date: string} */
    public function at(Flock $flock, CarbonImmutable $occurredAt): array
    {
        $movement = FlockMovement::query()
            ->where(fn ($query) => $query->where('source_flock_id', $flock->id)->orWhere('destination_flock_id', $flock->id))
            ->where('occurred_at', '<=', $occurredAt)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        $snapshot = $movement?->after[$flock->public_id] ?? null;
        if (! is_array($snapshot)) {
            throw new LotsConflict(
                'No existe una proyección histórica del lote para la fecha indicada.',
                code: 'WEIGHING_HISTORICAL_PROJECTION_UNAVAILABLE',
            );
        }

        $population = (int) ($snapshot['current_quantity'] ?? 0);
        $poultryHouseId = (int) ($snapshot['poultry_house_id'] ?? 0);
        $productionUnitId = (int) ($snapshot['production_unit_id'] ?? 0);
        if ($population < 1 || $poultryHouseId < 1 || $productionUnitId < 1) {
            throw new LotsConflict(
                'El lote no tenía aves para la fecha indicada.',
                code: 'WEIGHING_HISTORICAL_POPULATION_UNAVAILABLE',
            );
        }

        return [
            'flock_id' => $flock->public_id,
            'population' => $population,
            'poultry_house_id' => $poultryHouseId,
            'production_unit_id' => $productionUnitId,
            'entry_date' => (string) ($snapshot['entry_date'] ?? $flock->entry_date->format('Y-m-d')),
        ];
    }
}
