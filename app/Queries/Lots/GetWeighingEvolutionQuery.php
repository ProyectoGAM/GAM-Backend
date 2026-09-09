<?php

namespace App\Queries\Lots;

use App\Models\Lots\Flock;
use App\Models\Lots\Weighing;
use App\Services\Lots\WeighingMath;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final readonly class GetWeighingEvolutionQuery
{
    public function __construct(private WeighingMath $math) {}

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function execute(array $filters): array
    {
        $flock = Flock::query()->where('public_id', $filters['flock_id'])->firstOrFail();
        $query = Weighing::query()->where('flock_id', $flock->id);
        if (isset($filters['mode'])) {
            $query->where('mode', $filters['mode']);
        }
        if (isset($filters['date_from'])) {
            $query->where('occurred_at', '>=', CarbonImmutable::parse($filters['date_from'], config('lots.timezone'))->startOfDay()->utc());
        }
        if (isset($filters['date_to'])) {
            $query->where('occurred_at', '<', CarbonImmutable::parse($filters['date_to'], config('lots.timezone'))->addDay()->startOfDay()->utc());
        }

        $unit = (string) ($filters['unit'] ?? 'g');
        $weighings = $query
            ->orderBy('occurred_at')
            ->orderBy('public_id')
            ->limit(1001)
            ->get();
        if ($weighings->count() > 1000) {
            throw ValidationException::withMessages([
                'date_from' => ['La evolución contiene más de 1000 pesajes. Debes indicar date_from y date_to para acotar las fechas.'],
            ]);
        }

        $points = [];
        foreach ($weighings as $weighing) {
            $average = $this->math->fromGrams((string) $weighing->average_weight_g, $unit);
            $points[] = [
                'id' => $weighing->public_id,
                'date' => $weighing->occurred_at->format('Y-m-d\TH:i:sP'),
                'mode' => $weighing->mode,
                'represented_bird_count' => $weighing->represented_bird_count,
                'average_weight' => ['value' => $this->math->format($average, 6), 'unit' => $unit],
                'average_weight_g' => $this->math->format($weighing->average_weight_g, 6),
                'unit' => $unit,
                'outside_expected_range' => $weighing->outside_expected_range,
            ];
        }

        return $points;
    }
}
