<?php

namespace App\Actions\Lots;

use App\Exceptions\Lots\LotsConflict;
use App\Interfaces\Clock;
use App\Models\Lots\DailyWeighing;
use App\Models\Lots\DailyWeighingEntry;
use App\Models\Lots\FlockOperation;
use App\Models\User;
use App\Services\Lots\DailyWeighingPresenter;
use App\Services\Lots\DailyWeighingTotals;
use App\Services\Lots\LotsHistory;
use App\Services\Lots\RunLotsCommand;

final readonly class DeleteDailyWeighingEntryAction
{
    public function __construct(
        private RunLotsCommand $commands,
        private Clock $clock,
        private DailyWeighingTotals $totals,
        private DailyWeighingPresenter $presenter,
        private LotsHistory $history,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(DailyWeighing $daily, DailyWeighingEntry $entry, array $data, User $actor): FlockOperation
    {
        return $this->commands->execute(
            $actor,
            'weighings.manage',
            'daily-weighing.entry.delete',
            $data['idempotency_key'],
            ['daily_weighing' => $daily->public_id, 'entry' => $entry->public_id, ...$data],
            function (string $operationId) use ($daily, $entry, $data, $actor): array {
                $locked = DailyWeighing::query()->whereKey($daily->id)->lockForUpdate()->firstOrFail();
                if ($locked->version !== (int) $data['version']) {
                    throw new LotsConflict('La jornada cambió de versión. Actualiza los datos antes de reintentar.', code: 'DAILY_WEIGHING_VERSION_CONFLICT', meta: ['current_version' => $locked->version]);
                }
                $currentEntry = DailyWeighingEntry::query()->whereKey($entry->id)->where('daily_weighing_id', $locked->id)->whereNull('deleted_at')->firstOrFail();
                $before = $this->presenter->daily($locked);
                $entrySnapshot = $this->presenter->entry($currentEntry);
                $currentEntry->forceFill([
                    'deleted_at' => $this->clock->now(),
                    'deleted_by' => $actor->id,
                    'deletion_reason' => $data['reason'],
                ])->save();
                $this->totals->recalculate($locked);
                $after = $this->presenter->daily($locked->fresh());
                $this->history->audit($locked, $actor, 'daily_weighing_entry_deleted', 'Ingreso de pesaje diario eliminado', $operationId, ['daily_weighing' => $before, 'entry' => $entrySnapshot], ['daily_weighing' => $after, 'deleted_entry' => $entrySnapshot], $locked->flock->production_unit_id, reason: $data['reason']);

                return ['daily_weighing' => $after, 'deleted_entry_id' => $entry->public_id];
            },
        );
    }
}
