<?php

namespace App\Actions\Lots;

use App\Events\Lots\WeighingCorrected;
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
use App\Services\Lots\WeighingMath;
use App\Services\Lots\WeighingPresenter;
use Carbon\CarbonImmutable;

final readonly class CorrectWeighingAction
{
    public function __construct(
        private RunLotsCommand $commands,
        private FlockState $state,
        private WeighingFlockProjection $projection,
        private WeighingBuilder $builder,
        private WeighingPresenter $presenter,
        private WeighingMath $math,
        private LotsHistory $history,
        private LotsSnapshots $snapshots,
        private Clock $clock,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(Weighing $weighing, array $data, User $actor, string $source = 'api'): FlockOperation
    {
        return $this->commands->execute(
            $actor,
            'weighings.manage',
            'weighing.correct',
            $data['idempotency_key'],
            ['weighing' => $weighing->public_id, ...$data],
            function (string $operationId) use ($weighing, $data, $actor, $source): array {
                $settings = WeighingReferenceSettings::query()->lockForUpdate()->first();
                $current = Weighing::query()->whereKey($weighing->id)->lockForUpdate()->firstOrFail();
                if ($current->version !== (int) $data['version']) {
                    throw new LotsConflict(
                        'El pesaje cambió de versión. Actualiza los datos antes de reintentar.',
                        code: 'WEIGHING_VERSION_CONFLICT',
                        meta: ['current_version' => $current->version],
                    );
                }
                $current->loadMissing('flock');
                $currentFlock = Flock::query()->whereKey($current->flock_id)->firstOrFail();
                $destinationPublicId = (string) ($data['flock_id'] ?? $currentFlock->public_id);
                $locked = $this->state->lock(array_values(array_unique([$currentFlock->public_id, $destinationPublicId])));
                $destination = $locked->get($destinationPublicId);
                if ($destination === null) {
                    throw new LotsConflict('El lote indicado no existe.', code: 'WEIGHING_FLOCK_NOT_FOUND');
                }
                $merged = $this->mergedPayload($current, $data);
                $occurredAt = CarbonImmutable::parse($merged['occurred_at'])->utc();
                if ($occurredAt->greaterThan($this->clock->now())) {
                    throw new LotsConflict('La fecha del pesaje no puede ser futura.', code: 'WEIGHING_DATE_INVALID');
                }
                $historical = $this->projection->at($destination, $occurredAt);
                $historicalReference = $this->referenceFromCurrent($current);
                $built = $this->builder->build($merged, $destination, $historical, $settings, $historicalReference, $occurredAt);
                $before = $this->presenter->weighing($current->fresh(['flock', 'measurements']));
                $this->ensureChangedOutlierConfirmation($current, $built, (bool) ($data['confirm_out_of_range'] ?? false));

                $reference = $built['reference'];
                $current->forceFill([
                    'flock_id' => $destination->id,
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
                    'version' => $current->version + 1,
                ])->save();
                WeighingMeasurement::query()->where('weighing_id', $current->id)->delete();
                foreach ($built['measurements'] as $measurement) {
                    $row = new WeighingMeasurement;
                    $row->forceFill(['weighing_id' => $current->id, ...$measurement])->save();
                }
                $after = $this->presenter->weighing($current->fresh(['flock', 'measurements']));
                $this->history->audit($current, $actor, 'weighing_corrected', 'Pesaje rectificado', $operationId, $before, $after, $built['production_unit_id'], $source, $data['correction_reason']);
                event(new WeighingCorrected($operationId, [$currentFlock->public_id, $destination->public_id], $actor->id));

                return [
                    'flock' => $this->snapshots->flock($destination),
                    'weighing' => $after,
                    'warnings' => $built['reference'] === null ? [[
                        'code' => 'WEIGHING_REFERENCE_UNAVAILABLE',
                        'message' => 'No existe una configuración global de rangos para este pesaje.',
                    ]] : [],
                ];
            },
        );
    }

    /** @return array<string, mixed> */
    private function mergedPayload(Weighing $current, array $data): array
    {
        $current->loadMissing('measurements');
        $unit = (string) ($data['unit'] ?? $current->captured_unit);
        $measurements = $data['measurements'] ?? $this->measurementPayload($current, $unit);

        return [
            'flock_id' => $data['flock_id'] ?? $current->flock->public_id,
            'mode' => $data['mode'] ?? $current->mode,
            'unit' => $unit,
            'occurred_at' => $data['occurred_at'] ?? $current->occurred_at->toIso8601String(),
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $current->notes,
            'measurements' => $measurements,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function measurementPayload(Weighing $current, string $unit): array
    {
        $measurements = [];
        foreach ($current->measurements as $measurement) {
            if ($current->mode === 'individual') {
                $measurements[] = ['weight' => $this->math->fromGrams((string) $measurement->weight_g, $unit)];
            } else {
                $measurements[] = [
                    'total_weight' => $this->math->fromGrams((string) $measurement->total_weight_g, $unit),
                    'bird_count' => $measurement->bird_count,
                ];
            }
        }

        return $measurements;
    }

    /** @return array<string, mixed>|null */
    private function referenceFromCurrent(Weighing $current): ?array
    {
        if ($current->reference_version === null) {
            return null;
        }

        return [
            'version' => $current->reference_version,
            'unit' => $current->reference_unit,
            'adult_from_week' => $current->reference_adult_from_week,
            'chick_min_weight_g' => $current->reference_chick_min_weight_g,
            'chick_max_weight_g' => $current->reference_chick_max_weight_g,
            'adult_min_weight_g' => $current->reference_adult_min_weight_g,
            'adult_max_weight_g' => $current->reference_adult_max_weight_g,
        ];
    }

    /** @param array<string, mixed> $built */
    private function ensureChangedOutlierConfirmation(Weighing $current, array $built, bool $confirmed): void
    {
        $current->loadMissing('measurements');
        $changed = $this->rangeChanged($current, $built);
        $rows = [];
        foreach ($built['measurements'] as $measurement) {
            if (! $measurement['outside_expected_range']) {
                continue;
            }
            $old = $current->measurements->firstWhere('position', $measurement['position']);
            $rowChanged = $old === null
                || (string) ($old->weight_g ?? '') !== (string) ($measurement['weight_g'] ?? '')
                || (string) ($old->total_weight_g ?? '') !== (string) ($measurement['total_weight_g'] ?? '')
                || (int) ($old->bird_count ?? 0) !== (int) ($measurement['bird_count'] ?? 0)
                || $changed;
            if ($rowChanged) {
                $rows[] = [
                    'position' => $measurement['position'],
                    'value' => $measurement['weight_g'] ?? $measurement['average_weight_g'],
                    'unit' => 'g',
                    'stage' => $built['stage'],
                    'min_weight_g' => $built['min_weight_g'],
                    'max_weight_g' => $built['max_weight_g'],
                ];
            }
        }
        if ($rows !== [] && ! $confirmed) {
            throw new LotsConflict(
                'Debes confirmar los valores fuera del rango esperado antes de guardar el pesaje.',
                code: 'WEIGHING_OUT_OF_RANGE_CONFIRMATION_REQUIRED',
                meta: ['rows' => $rows],
            );
        }
    }

    /** @param array<string, mixed> $built */
    private function rangeChanged(Weighing $current, array $built): bool
    {
        return $current->stage !== $built['stage']
            || (string) $current->min_weight_g !== (string) $built['min_weight_g']
            || (string) $current->max_weight_g !== (string) $built['max_weight_g'];
    }
}
