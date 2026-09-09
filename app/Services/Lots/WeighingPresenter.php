<?php

namespace App\Services\Lots;

use App\Models\Lots\Weighing;
use App\Models\Lots\WeighingReferenceSettings;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class WeighingPresenter
{
    /** @return array<string, mixed> */
    public function weighing(Weighing|array $weighing): array
    {
        if ($weighing instanceof Weighing) {
            $weighing->loadMissing('measurements', 'flock');
            $data = $weighing->toArray();
            $data['flock_id'] = $weighing->flock->public_id;
            $data['measurements'] = $weighing->measurements->all();
        } else {
            $data = $weighing;
        }

        $measurements = [];
        foreach (($data['measurements'] ?? []) as $measurement) {
            $item = is_object($measurement) ? $measurement->toArray() : $measurement;
            $measurements[] = [
                'position' => (int) ($item['position'] ?? 0),
                'weight_g' => isset($item['weight_g']) ? $this->decimal($item['weight_g'], 1) : null,
                'total_weight_g' => isset($item['total_weight_g']) ? $this->decimal($item['total_weight_g'], 1) : null,
                'bird_count' => isset($item['bird_count']) ? (int) $item['bird_count'] : null,
                'average_weight_g' => isset($item['average_weight_g']) ? $this->decimal($item['average_weight_g'], 6) : null,
                'outside_expected_range' => (bool) ($item['outside_expected_range'] ?? false),
            ];
        }

        $reference = null;
        if (($data['reference_version'] ?? null) !== null) {
            $reference = [
                'version' => (int) $data['reference_version'],
                'unit' => $data['reference_unit'],
                'adult_from_week' => (int) $data['reference_adult_from_week'],
                'chick_min_weight_g' => $this->decimal($data['reference_chick_min_weight_g'], 1),
                'chick_max_weight_g' => $this->decimal($data['reference_chick_max_weight_g'], 1),
                'adult_min_weight_g' => $this->decimal($data['reference_adult_min_weight_g'], 1),
                'adult_max_weight_g' => $this->decimal($data['reference_adult_max_weight_g'], 1),
            ];
        }

        $total = $this->decimal($data['total_weight_g'] ?? null, 1);
        $average = $this->decimal($data['average_weight_g'] ?? null, 6);

        return [
            'id' => $data['public_id'] ?? null,
            'flock_id' => $data['flock_id'] ?? null,
            'poultry_house_id' => isset($data['poultry_house_id']) ? (int) $data['poultry_house_id'] : null,
            'production_unit_id' => isset($data['production_unit_id']) ? (int) $data['production_unit_id'] : null,
            'mode' => $data['mode'] ?? null,
            'occurred_at' => $this->date($data['occurred_at'] ?? null),
            'unit' => $data['captured_unit'] ?? null,
            'captured_unit' => $data['captured_unit'] ?? null,
            'notes' => $data['notes'] ?? null,
            'stage' => $data['stage'] ?? null,
            'expected_range' => $data['stage'] === null ? null : [
                'min_weight_g' => $this->decimal($data['min_weight_g'] ?? null, 1),
                'max_weight_g' => $this->decimal($data['max_weight_g'] ?? null, 1),
                'unit' => 'g',
            ],
            'reference' => $reference,
            'represented_bird_count' => (int) ($data['represented_bird_count'] ?? 0),
            'total_weight_g' => $total,
            'average_weight_g' => $average,
            'total_weight' => ['value' => $total, 'unit' => 'g'],
            'average_weight' => ['value' => $average, 'unit' => 'g'],
            'outside_expected_range' => (bool) ($data['outside_expected_range'] ?? false),
            'version' => (int) ($data['version'] ?? 0),
            'created_by' => isset($data['created_by']) ? (int) $data['created_by'] : null,
            'measurements' => $measurements,
        ];
    }

    /** @return array<string, mixed>|null */
    public function settings(?WeighingReferenceSettings $settings): ?array
    {
        if ($settings === null) {
            return null;
        }

        return [
            'adult_from_week' => $settings->adult_from_week,
            'chick_min_weight_g' => $this->decimal($settings->chick_min_weight_g, 1),
            'chick_max_weight_g' => $this->decimal($settings->chick_max_weight_g, 1),
            'adult_min_weight_g' => $this->decimal($settings->adult_min_weight_g, 1),
            'adult_max_weight_g' => $this->decimal($settings->adult_max_weight_g, 1),
            'unit' => $settings->captured_unit,
            'captured_unit' => $settings->captured_unit,
            'version' => $settings->version,
        ];
    }

    private function decimal(mixed $value, int $scale): ?string
    {
        if ($value === null) {
            return null;
        }

        return (string) BigDecimal::of((string) $value)->toScale($scale, RoundingMode::HalfUp);
    }

    private function date(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:sP');
        }

        return $value === null ? null : (string) $value;
    }
}
