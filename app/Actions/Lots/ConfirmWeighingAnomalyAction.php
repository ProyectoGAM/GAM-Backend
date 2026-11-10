<?php

namespace App\Actions\Lots;

use App\Exceptions\Lots\LotsConflict;
use App\Interfaces\Clock;
use App\Models\Lots\DailyWeighing;
use App\Models\Lots\DailyWeighingEntry;
use App\Models\Lots\FlockOperation;
use App\Models\Lots\Weighing;
use App\Models\User;
use App\Services\Lots\DailyWeighingPresenter;
use App\Services\Lots\LotsHistory;
use App\Services\Lots\RunLotsCommand;
use App\Services\Lots\WeighingAnomalyAlerts;
use App\Services\Lots\WeighingPresenter;

final readonly class ConfirmWeighingAnomalyAction
{
    public function __construct(
        private RunLotsCommand $commands,
        private LotsHistory $history,
        private WeighingAnomalyAlerts $alerts,
        private DailyWeighingPresenter $dailyPresenter,
        private WeighingPresenter $presenter,
        private Clock $clock,
    ) {}

    public function daily(DailyWeighing $daily, DailyWeighingEntry $entry, int $version, string $key, User $actor): FlockOperation
    {
        return $this->commands->execute(
            $actor, 'can_manage_weighings', 'daily-weighing.entry.confirm-anomaly', $key,
            ['daily_weighing' => $daily->public_id, 'entry' => $entry->public_id, 'version' => $version],
            function (string $operationId) use ($daily, $entry, $version, $actor): array {
                $locked = DailyWeighing::query()->whereKey($daily->id)->lockForUpdate()->firstOrFail();
                $measurement = $locked->entries()->whereKey($entry->id)->firstOrFail();
                $this->ensureReviewable($measurement, $locked->version, $version);
                if ($measurement->anomaly_reviewed_at === null) {
                    $before = $this->dailyPresenter->entry($measurement);
                    $this->confirm($measurement, $actor, $locked->flock->production_unit_id);
                    $locked->increment('version');
                    $this->history->audit($measurement, $actor, 'weighing_anomaly_confirmed', 'Anomalía de pesaje confirmada', $operationId,
                        $before, ['daily_weighing_id' => $locked->public_id, ...$this->dailyPresenter->entry($measurement)], $locked->flock->production_unit_id);
                }

                return ['daily_weighing' => $this->dailyPresenter->daily($locked), 'entry' => $this->dailyPresenter->entry($measurement)];
            },
        );
    }

    public function weighing(Weighing $weighing, int $version, string $key, User $actor): FlockOperation
    {
        return $this->commands->execute(
            $actor, 'can_manage_weighings', 'weighing.confirm-anomaly', $key,
            ['weighing' => $weighing->public_id, 'version' => $version],
            function (string $operationId) use ($weighing, $version, $actor): array {
                $locked = Weighing::query()->whereKey($weighing->id)->lockForUpdate()->firstOrFail();
                $this->ensureReviewable($locked, $locked->version, $version);
                if ($locked->anomaly_reviewed_at === null) {
                    $before = $this->presenter->weighing($locked);
                    $this->confirm($locked, $actor, $locked->production_unit_id);
                    $locked->increment('version');
                    $this->history->audit($locked, $actor, 'weighing_anomaly_confirmed', 'Anomalía de pesaje confirmada', $operationId,
                        $before, $this->presenter->weighing($locked), $locked->production_unit_id);
                }

                return ['weighing' => $this->presenter->weighing($locked)];
            },
        );
    }

    private function ensureReviewable(DailyWeighingEntry|Weighing $measurement, int $currentVersion, int $version): void
    {
        if ($currentVersion !== $version) {
            throw new LotsConflict('El pesaje cambió de versión. Actualizá los datos antes de confirmar.', code: 'WEIGHING_ANOMALY_VERSION_CONFLICT',
                meta: ['current_version' => $currentVersion]);
        }
        if (! $measurement->outside_expected_range) {
            throw new LotsConflict('El registro no tiene un dato fuera del rango esperado.', code: 'WEIGHING_ANOMALY_NOT_PRESENT');
        }
    }

    private function confirm(DailyWeighingEntry|Weighing $measurement, User $actor, int $unitId): void
    {
        $measurement->forceFill(['anomaly_reviewed_at' => $this->clock->now(), 'anomaly_reviewed_by' => $actor->id])->save();
        $this->alerts->resolve($measurement, $unitId);
    }
}
