<?php

namespace App\Actions\Lots;

use App\Enums\Lots\FlockStatus;
use App\Events\Lots\WeighingRecorded;
use App\Exceptions\Lots\LotsConflict;
use App\Interfaces\Clock;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockOperation;
use App\Models\Lots\Weighing;
use App\Models\Lots\WeighingMeasurement;
use App\Models\Lots\WeighingReferenceSettings;
use App\Models\User;
use App\Services\Lots\FlockState;
use App\Services\Lots\LotsHistory;
use App\Services\Lots\LotsSnapshots;
use App\Services\Lots\RunLotsCommand;
use App\Services\Lots\WeighingBuilder;
use App\Services\Lots\WeighingFlockProjection;
use App\Services\Lots\WeighingPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class RecordWeighingAction
{
    public function __construct(
        private RunLotsCommand $commands,
        private FlockState $state,
        private WeighingFlockProjection $projection,
        private WeighingBuilder $builder,
        private WeighingPresenter $presenter,
        private LotsHistory $history,
        private LotsSnapshots $snapshots,
        private Clock $clock,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(Flock $flock, array $data, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute(
            $actor,
            'weighings.manage',
            'weighing.record',
            $data['idempotency_key'],
            ['flock' => $flock->public_id, ...$data],
            function (string $operationId) use ($flock, $data, $actor, $source): array {
                $settings = WeighingReferenceSettings::query()->lockForUpdate()->first();
                $locked = $this->state->lock([$flock->public_id])->get($flock->public_id);
                if (! in_array($locked->status, [FlockStatus::Active, FlockStatus::Quarantined], true)) {
                    throw new LotsConflict('Sólo se pueden registrar pesajes en lotes activos o en cuarentena.', code: 'WEIGHING_FLOCK_LIFECYCLE_CONFLICT');
                }
                $occurredAt = isset($data['occurred_at'])
                    ? CarbonImmutable::parse($data['occurred_at'])->utc()
                    : $this->clock->now();
                if ($occurredAt->greaterThan($this->clock->now()) || $occurredAt->lessThan($locked->established_at)) {
                    throw new LotsConflict('La fecha del pesaje debe pertenecer a la existencia del lote y no puede ser futura.', code: 'WEIGHING_DATE_INVALID');
                }
                $historical = $this->projection->at($locked, $occurredAt);
                $built = $this->builder->build($data, $locked, $historical, $settings, null, $occurredAt);
                $this->ensureConfirmation($built, (bool) ($data['confirm_out_of_range'] ?? false));

                $weighing = new Weighing;
                $reference = $built['reference'];
                $weighing->forceFill([
                    'public_id' => $data['id'] ?? (string) Str::ulid(),
                    'flock_id' => $locked->id,
                    'poultry_house_id' => $built['poultry_house_id'],
                    'production_unit_id' => $built['production_unit_id'],
                    'mode' => $built['mode'],
                    'occurred_at' => $built['occurred_at'],
                    'captured_unit' => $built['captured_unit'],
                    'notes' => $built['notes'],
                    'stage' => $built['stage'],
                    'min_weight_g' => $built['min_weight_g'],
                    'max_weight_g' => $built['max_weight_g'],
                    'reference_adult_from_week' => $reference['adult_from_week'] ?? null,
                    'reference_chick_min_weight_g' => $reference['chick_min_weight_g'] ?? null,
                    'reference_chick_max_weight_g' => $reference['chick_max_weight_g'] ?? null,
                    'reference_adult_min_weight_g' => $reference['adult_min_weight_g'] ?? null,
                    'reference_adult_max_weight_g' => $reference['adult_max_weight_g'] ?? null,
                    'reference_unit' => $reference['unit'] ?? null,
                    'reference_version' => $reference['version'] ?? null,
                    'represented_bird_count' => $built['represented_bird_count'],
                    'total_weight_g' => $built['total_weight_g'],
                    'average_weight_g' => $built['average_weight_g'],
                    'outside_expected_range' => $built['outside_expected_range'],
                    'version' => 1,
                    'created_by' => $actor->id,
                ])->save();
                $this->saveMeasurements($weighing, $built['measurements']);
                $after = $this->presenter->weighing($weighing->fresh(['flock', 'measurements']));
                $this->history->audit($weighing, $actor, 'weighing_recorded', 'Pesaje registrado', $operationId, [], $after, $built['production_unit_id'], $source);
                event(new WeighingRecorded($operationId, [$locked->public_id], $actor->id));

                return [
                    'flock' => $this->snapshots->flock($locked),
                    'weighing' => $after,
                    'warnings' => $reference === null ? [[
                        'code' => 'WEIGHING_REFERENCE_UNAVAILABLE',
                        'message' => 'No existe una configuración global de rangos para este pesaje.',
                    ]] : [],
                ];
            },
        );
    }

    /** @param list<array<string, mixed>> $measurements */
    private function saveMeasurements(Weighing $weighing, array $measurements): void
    {
        foreach ($measurements as $measurement) {
            $row = new WeighingMeasurement;
            $row->forceFill(['weighing_id' => $weighing->id, ...$measurement])->save();
        }
    }

    /** @param array<string, mixed> $built */
    private function ensureConfirmation(array $built, bool $confirmed): void
    {
        if (! $built['outside_expected_range'] || $confirmed) {
            return;
        }
        $rows = [];
        foreach ($built['measurements'] as $measurement) {
            if (! $measurement['outside_expected_range']) {
                continue;
            }
            $rows[] = [
                'position' => $measurement['position'],
                'value' => $measurement['weight_g'] ?? $measurement['average_weight_g'],
                'unit' => 'g',
                'stage' => $built['stage'],
                'min_weight_g' => $built['min_weight_g'],
                'max_weight_g' => $built['max_weight_g'],
            ];
        }
        throw new LotsConflict(
            'Debes confirmar los valores fuera del rango esperado antes de guardar el pesaje.',
            code: 'WEIGHING_OUT_OF_RANGE_CONFIRMATION_REQUIRED',
            meta: ['rows' => $rows],
        );
    }
}
