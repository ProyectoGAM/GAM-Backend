<?php

namespace App\Queries\Lots;

use App\Models\Lots\Weighing;
use App\Services\Lots\WeighingMath;

final readonly class GetWeighingDistributionQuery
{
    public function __construct(private WeighingMath $math) {}

    /** @return array<string, mixed> */
    public function execute(Weighing $weighing, string $unit = 'g'): array
    {
        if ($weighing->mode !== 'individual') {
            return [
                'available' => false,
                'reason' => 'group_mode',
                'unit' => $unit,
                'n' => null,
                'mean' => null,
                'sample_stddev' => null,
                'bins' => [],
                'curve' => null,
            ];
        }
        $weights = $weighing->measurements()->pluck('weight_g')->map(static fn ($value): string => (string) $value)->all();
        $distribution = $this->math->distribution($weights, $unit);

        return [
            'available' => true,
            'reason' => $distribution['reason'],
            'unit' => $unit,
            'n' => count($weights),
            'mean' => $distribution['mean'],
            'sample_stddev' => $distribution['stddev'],
            'bins' => $distribution['bins'],
            'curve' => $distribution['curve'],
        ];
    }
}
