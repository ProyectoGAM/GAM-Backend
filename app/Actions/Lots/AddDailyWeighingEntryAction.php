<?php

namespace App\Actions\Lots;

use App\Enums\Lots\FlockStatus;
use App\Exceptions\Lots\LotsConflict;
use App\Interfaces\Clock;
use App\Models\Lots\DailyWeighing;
use App\Models\Lots\DailyWeighingEntry;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\Lots\WeighingReferenceSettings;
use App\Models\User;
use App\Services\Lots\BreedWeighingReference;
use App\Services\Lots\DailyWeighingPresenter;
use App\Services\Lots\DailyWeighingTotals;
use App\Services\Lots\FlockState;
use App\Services\Lots\LotsHistory;
use App\Services\Lots\RunLotsCommand;
use App\Services\Lots\WeighingFlockProjection;
use App\Services\Lots\WeighingMath;
use App\ValueObjects\Lots\FlockAge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class AddDailyWeighingEntryAction
{
    public function __construct(
        private RunLotsCommand $commands,
        private FlockState $state,
        private WeighingFlockProjection $projection,
        private Clock $clock,
        private WeighingMath $math,
        private BreedWeighingReference $references,
        private DailyWeighingTotals $totals,
        private DailyWeighingPresenter $presenter,
        private LotsHistory $history,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(Flock $flock, array $data, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute(
            $actor,
            'weighings.manage',
            'daily-weighing.entry.add',
            $data['idempotency_key'],
            ['flock' => $flock->public_id, ...$data],
            function (string $operationId) use ($flock, $data, $actor, $source): array {
                if (DB::connection()->getDriverName() === 'pgsql') {
                    DB::statement("SELECT pg_advisory_xact_lock(hashtext('weighing_reference_settings'))");
                }
                $settings = WeighingReferenceSettings::query()->lockForUpdate()->first();
                $locked = $this->state->lock([$flock->public_id])->get($flock->public_id);
                if (! in_array($locked->status, [FlockStatus::Active, FlockStatus::Quarantined], true)) {
                    throw new LotsConflict('Sólo se pueden registrar ingresos en lotes activos o en cuarentena.', code: 'DAILY_WEIGHING_FLOCK_LIFECYCLE_CONFLICT');
                }
                $occurredAt = $this->clock->now();
                $localDate = $occurredAt->setTimezone(config('lots.timezone'))->format('Y-m-d');
                $projection = $this->projection->at($locked, $occurredAt);
                $daily = DailyWeighing::query()->where('flock_id', $locked->id)->whereDate('local_date', $localDate)->lockForUpdate()->first();
                $before = $daily === null ? [] : $this->presenter->daily($daily);
                if ($daily === null) {
                    $locked->loadMissing('breed');
                    $week = FlockAge::on($projection['entry_date'], $occurredAt, config('lots.timezone'))->week;
                    $selected = $this->references->forWeek($locked->breed, $settings, $week);
                    $daily = new DailyWeighing;
                    $daily->forceFill([
                        'flock_id' => $locked->id,
                        'local_date' => $localDate,
                        'stage' => $selected['stage'] ?? null,
                        'min_weight_g' => $selected['min_weight_g'] ?? null,
                        'max_weight_g' => $selected['max_weight_g'] ?? null,
                        'reference_version' => $selected['reference_version'] ?? null,
                        'reference_source' => $selected['source'] ?? null,
                        'reference_breed_id' => $selected['breed_id'] ?? null,
                        'reference_breed_version' => $selected['breed_version'] ?? null,
                        'version' => 1,
                    ]);
                }
                $mode = $data['mode'];
                $birds = $mode === 'individual' ? 1 : (int) $data['bird_count'];
                $total = $this->math->toGrams((string) ($mode === 'individual' ? $data['weight'] : $data['total_weight']), 'g');
                $average = $this->math->divide($total, $birds);
                $outside = $this->math->isOutside($average, $daily->min_weight_g, $daily->max_weight_g);
                if ($outside && ! ($data['confirm_out_of_range'] ?? false)) {
                    throw new LotsConflict(
                        'Debes confirmar el ingreso fuera del rango esperado antes de guardarlo.',
                        code: 'DAILY_WEIGHING_OUT_OF_RANGE_CONFIRMATION_REQUIRED',
                        meta: ['average_weight_g' => $average, 'min_weight_g' => $daily->min_weight_g, 'max_weight_g' => $daily->max_weight_g],
                    );
                }
                if ($daily->represented_bird_count + $birds > $projection['population']) {
                    throw new LotsConflict('La muestra diaria no puede superar las aves vivas del lote.', code: 'DAILY_WEIGHING_POPULATION_EXCEEDED');
                }
                if (! $daily->exists) {
                    $daily->save();
                }
                $entry = new DailyWeighingEntry;
                $entry->forceFill([
                    'public_id' => (string) Str::ulid(),
                    'daily_weighing_id' => $daily->id,
                    'mode' => $mode,
                    'weight_g' => $mode === 'individual' ? $total : null,
                    'bird_count' => $birds,
                    'total_weight_g' => $total,
                    'average_weight_g' => $average,
                    'outside_expected_range' => $outside,
                    'occurred_at' => $occurredAt,
                    'created_by' => $actor->id,
                ])->save();
                $this->totals->recalculate($daily);
                $after = $this->presenter->daily($daily->fresh());
                $this->history->audit($daily, $actor, 'daily_weighing_entry_added', 'Ingreso de pesaje diario registrado', $operationId, $before, ['daily_weighing' => $after, 'entry' => $this->presenter->entry($entry)], $projection['production_unit_id'], $source);

                return ['daily_weighing' => $after, 'entry' => $this->presenter->entry($entry)];
            },
        );
    }
}
