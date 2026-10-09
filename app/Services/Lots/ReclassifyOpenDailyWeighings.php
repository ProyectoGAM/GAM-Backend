<?php

namespace App\Services\Lots;

use App\Interfaces\Clock;
use App\Models\Lots\DailyWeighing;
use App\Models\Lots\WeighingReferenceSettings;
use App\Models\User;
use App\ValueObjects\Lots\FlockAge;

final readonly class ReclassifyOpenDailyWeighings
{
    public function __construct(
        private Clock $clock,
        private BreedWeighingReference $references,
        private WeighingMath $math,
        private DailyWeighingTotals $totals,
        private DailyWeighingPresenter $presenter,
        private LotsHistory $history,
    ) {}

    public function execute(WeighingReferenceSettings $settings, User $actor, string $operationId, string $source = 'api', ?int $breedId = null): void
    {
        $today = $this->clock->now()->setTimezone(config('lots.timezone'))->format('Y-m-d');
        $query = DailyWeighing::query()
            ->whereDate('local_date', $today)
            ->with('flock.breed')
            ->orderBy('id')
            ->lockForUpdate();
        if ($breedId !== null) {
            $query->whereHas('flock', fn ($flockQuery) => $flockQuery->where('breed_id', $breedId));
        }

        foreach ($query->get() as $daily) {
            $before = $this->presenter->daily($daily);
            $week = FlockAge::on($daily->flock->entry_date->format('Y-m-d'), $this->clock->now(), config('lots.timezone'))->week;
            $selected = $this->references->forWeek($daily->flock->breed, $settings, $week);
            $daily->forceFill([
                'stage' => $selected['stage'],
                'min_weight_g' => $selected['min_weight_g'],
                'max_weight_g' => $selected['max_weight_g'],
                'reference_version' => $selected['reference_version'],
                'reference_source' => $selected['source'],
                'reference_breed_id' => $selected['breed_id'],
                'reference_breed_version' => $selected['breed_version'],
            ])->save();
            $daily->entries()->orderBy('id')->chunkById(500, function ($entries) use ($selected): void {
                foreach ($entries as $entry) {
                    $outside = $this->math->isOutside((string) $entry->average_weight_g, $selected['min_weight_g'], $selected['max_weight_g']);
                    if ($entry->outside_expected_range !== $outside) {
                        $entry->forceFill(['outside_expected_range' => $outside])->save();
                    }
                }
            });
            $this->totals->recalculate($daily);
            $this->history->audit($daily, $actor, 'daily_weighing_reference_updated', 'Rango de jornada abierta actualizado', $operationId, $before, $this->presenter->daily($daily->fresh()), $daily->flock->production_unit_id, $source);
        }
    }
}
