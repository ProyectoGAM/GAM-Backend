<?php

namespace App\Services\Lots;

use App\Models\Lots\DailyWeighing;

class DailyWeighingTotals
{
    public function __construct(private readonly WeighingMath $math) {}

    public function recalculate(DailyWeighing $daily): void
    {
        $totals = (array) $daily->entries()->toBase()->selectRaw('COALESCE(SUM(bird_count), 0) as birds, COALESCE(SUM(total_weight_g), 0) as weight, COALESCE(SUM(CASE WHEN outside_expected_range THEN 1 ELSE 0 END), 0) as anomalies')->first();
        $birds = (int) ($totals['birds'] ?? 0);
        $weight = $this->math->format((string) ($totals['weight'] ?? '0'), 1);
        $daily->forceFill([
            'represented_bird_count' => $birds,
            'total_weight_g' => $weight,
            'average_weight_g' => $birds === 0 ? null : $this->math->divide($weight, $birds),
            'anomalous_entry_count' => (int) ($totals['anomalies'] ?? 0),
            'version' => $daily->version + 1,
        ])->save();
    }
}
