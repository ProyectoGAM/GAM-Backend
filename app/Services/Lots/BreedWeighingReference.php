<?php

namespace App\Services\Lots;

use App\Models\Lots\Breed;
use App\Models\Lots\WeighingReferenceSettings;

final readonly class BreedWeighingReference
{
    public function __construct(private WeighingMath $math) {}

    /** @return array<string, mixed>|null */
    public function resolve(Breed $breed, ?WeighingReferenceSettings $settings): ?array
    {
        if ($settings === null) {
            return null;
        }

        $chickCustom = $breed->chick_min_weight_g !== null;
        $adultCustom = $breed->adult_min_weight_g !== null;

        return [
            'version' => $settings->version,
            'unit' => $settings->captured_unit,
            'adult_from_week' => $settings->adult_from_week,
            'chick_min_weight_g' => (string) ($chickCustom ? $breed->chick_min_weight_g : $settings->chick_min_weight_g),
            'chick_max_weight_g' => (string) ($chickCustom ? $breed->chick_max_weight_g : $settings->chick_max_weight_g),
            'adult_min_weight_g' => (string) ($adultCustom ? $breed->adult_min_weight_g : $settings->adult_min_weight_g),
            'adult_max_weight_g' => (string) ($adultCustom ? $breed->adult_max_weight_g : $settings->adult_max_weight_g),
            'chick_source' => $chickCustom ? 'breed' : 'global',
            'adult_source' => $adultCustom ? 'breed' : 'global',
            'breed_id' => $breed->id,
            'breed_version' => $breed->version,
        ];
    }

    /** @return array{stage: string, min_weight_g: string, max_weight_g: string, source: string, reference_version: int, breed_id: int, breed_version: int}|null */
    public function forWeek(Breed $breed, ?WeighingReferenceSettings $settings, int $week): ?array
    {
        $reference = $this->resolve($breed, $settings);
        if ($reference === null) {
            return null;
        }

        $stage = $week >= $reference['adult_from_week'] ? 'adult' : 'chick';

        return [
            'stage' => $stage,
            'min_weight_g' => $reference[$stage.'_min_weight_g'],
            'max_weight_g' => $reference[$stage.'_max_weight_g'],
            'source' => $reference[$stage.'_source'],
            'reference_version' => $reference['version'],
            'breed_id' => $breed->id,
            'breed_version' => $breed->version,
        ];
    }

    /** @return array<string, mixed> */
    public function catalog(Breed $breed, ?WeighingReferenceSettings $settings): array
    {
        $reference = $this->resolve($breed, $settings);
        $overrides = [];
        foreach (['chick_min', 'chick_max', 'adult_min', 'adult_max'] as $field) {
            $name = $field.'_weight_g';
            $overrides[$name] = $breed->{$name} === null ? null : $this->math->format((string) $breed->{$name}, 1);
        }

        return $this->canonical([
            'id' => $breed->id,
            'name' => $breed->name,
            'status' => $breed->status,
            'version' => $breed->version,
            'range_overrides' => $overrides,
            'expected_ranges' => $reference === null ? null : [
                'adult_from_week' => $reference['adult_from_week'],
                'reference_version' => $reference['version'],
                'chick' => [
                    'min_weight_g' => $this->math->format($reference['chick_min_weight_g'], 1),
                    'max_weight_g' => $this->math->format($reference['chick_max_weight_g'], 1),
                    'source' => $reference['chick_source'],
                ],
                'adult' => [
                    'min_weight_g' => $this->math->format($reference['adult_min_weight_g'], 1),
                    'max_weight_g' => $this->math->format($reference['adult_max_weight_g'], 1),
                    'source' => $reference['adult_source'],
                ],
            ],
        ]);
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    private function canonical(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }
        uksort($value, static fn (string $left, string $right): int => strlen($left) <=> strlen($right) ?: strcmp($left, $right));

        return $value;
    }
}
