<?php

namespace App\Services\Lots;

use App\Enums\Lots\BirdGrowthStage;
use App\Exceptions\Lots\LotsConflict;
use App\Models\Lots\Flock;
use App\Models\Lots\WeighingReferenceSettings;
use App\ValueObjects\Lots\FlockAge;
use Carbon\CarbonImmutable;

final readonly class WeighingBuilder
{
    public function __construct(private WeighingMath $math) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array{population: int, poultry_house_id: int, production_unit_id: int, entry_date: string}  $projection
     * @param  array<string, mixed>|null  $historicalReference
     * @return array<string, mixed>
     */
    public function build(array $data, Flock $flock, array $projection, ?WeighingReferenceSettings $settings, ?array $historicalReference, CarbonImmutable $occurredAt): array
    {
        $mode = (string) $data['mode'];
        $unit = (string) $data['unit'];
        $measurements = $data['measurements'];
        $rows = [];
        $totalWeight = [];
        $represented = 0;
        $reference = $this->reference($settings, $historicalReference);
        $age = FlockAge::on($projection['entry_date'], $occurredAt, config('lots.timezone'));
        $stage = null;
        $minimum = null;
        $maximum = null;
        if ($reference !== null) {
            $stage = $age->week >= $reference['adult_from_week'] ? BirdGrowthStage::Adult->value : BirdGrowthStage::Chick->value;
            $minimum = $stage === BirdGrowthStage::Adult->value ? $reference['adult_min_weight_g'] : $reference['chick_min_weight_g'];
            $maximum = $stage === BirdGrowthStage::Adult->value ? $reference['adult_max_weight_g'] : $reference['chick_max_weight_g'];
        }

        foreach (array_values($measurements) as $index => $measurement) {
            $position = $index + 1;
            if ($mode === 'individual') {
                $weight = $this->math->toGrams((string) $measurement['weight'], $unit);
                $outside = $this->math->isOutside($weight, $minimum, $maximum);
                $rows[] = [
                    'position' => $position,
                    'weight_g' => $weight,
                    'total_weight_g' => null,
                    'bird_count' => null,
                    'average_weight_g' => null,
                    'outside_expected_range' => $outside,
                ];
                $totalWeight[] = $weight;
                $represented++;
            } elseif ($mode === 'group') {
                $weight = $this->math->toGrams((string) $measurement['total_weight'], $unit);
                $birdCount = (int) $measurement['bird_count'];
                $average = $this->math->divide($weight, $birdCount, 6);
                $outside = $this->math->isOutside($average, $minimum, $maximum);
                $rows[] = [
                    'position' => $position,
                    'weight_g' => null,
                    'total_weight_g' => $weight,
                    'bird_count' => $birdCount,
                    'average_weight_g' => $average,
                    'outside_expected_range' => $outside,
                ];
                $totalWeight[] = $weight;
                $represented += $birdCount;
            } else {
                throw new LotsConflict('El modo de pesaje no es válido.', code: 'WEIGHING_MODE_INVALID');
            }
        }

        if ($represented < 1 || $represented > $projection['population']) {
            throw new LotsConflict(
                'La cantidad pesada no puede superar las aves vivas del lote para la fecha indicada.',
                code: 'WEIGHING_POPULATION_EXCEEDED',
                meta: ['population' => $projection['population'], 'represented_bird_count' => $represented],
            );
        }

        $total = $this->math->sum(...$totalWeight);

        return [
            'flock_id' => $flock->id,
            'flock_public_id' => $flock->public_id,
            'poultry_house_id' => $projection['poultry_house_id'],
            'production_unit_id' => $projection['production_unit_id'],
            'mode' => $mode,
            'occurred_at' => $occurredAt,
            'captured_unit' => $unit,
            'notes' => $data['notes'] ?? null,
            'stage' => $stage,
            'min_weight_g' => $minimum,
            'max_weight_g' => $maximum,
            'reference' => $reference,
            'represented_bird_count' => $represented,
            'total_weight_g' => $total,
            'average_weight_g' => $this->math->divide($total, $represented, 6),
            'outside_expected_range' => collect($rows)->contains(static fn (array $row): bool => $row['outside_expected_range']),
            'measurements' => $rows,
        ];
    }

    /** @return array<string, mixed>|null */
    private function reference(?WeighingReferenceSettings $settings, ?array $historicalReference): ?array
    {
        if ($historicalReference !== null) {
            return $historicalReference;
        }
        if ($settings === null) {
            return null;
        }

        return [
            'version' => $settings->version,
            'unit' => $settings->captured_unit,
            'adult_from_week' => $settings->adult_from_week,
            'chick_min_weight_g' => (string) $settings->chick_min_weight_g,
            'chick_max_weight_g' => (string) $settings->chick_max_weight_g,
            'adult_min_weight_g' => (string) $settings->adult_min_weight_g,
            'adult_max_weight_g' => (string) $settings->adult_max_weight_g,
        ];
    }
}
