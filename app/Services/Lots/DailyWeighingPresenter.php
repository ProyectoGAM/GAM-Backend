<?php

namespace App\Services\Lots;

use App\Interfaces\Clock;
use App\Models\Lots\DailyWeighing;
use App\Models\Lots\DailyWeighingEntry;

class DailyWeighingPresenter
{
    public function __construct(private readonly Clock $clock, private readonly WeighingMath $math) {}

    /** @return array<string, mixed> */
    public function daily(DailyWeighing $daily): array
    {
        $daily->loadMissing('flock', 'lastEntry');

        return [
            'id' => $daily->public_id,
            'flock_id' => $daily->flock->public_id,
            'date' => $daily->local_date->format('Y-m-d'),
            'status' => $daily->local_date->format('Y-m-d') === $this->clock->now()->setTimezone(config('lots.timezone'))->format('Y-m-d') ? 'in_progress' : 'closed',
            'expected_range' => $daily->min_weight_g === null ? null : [
                'stage' => $daily->stage,
                'min_weight_g' => $this->math->format((string) $daily->min_weight_g, 1),
                'max_weight_g' => $this->math->format((string) $daily->max_weight_g, 1),
                'unit' => 'g',
                'reference_version' => $daily->reference_version,
                'source' => $daily->reference_source ?? 'global',
                'breed_id' => $daily->reference_breed_id,
                'breed_version' => $daily->reference_breed_version,
            ],
            'version' => $daily->version,
            'represented_bird_count' => $daily->represented_bird_count,
            'total_weight_g' => $this->math->format((string) $daily->total_weight_g, 1),
            'average_weight_g' => $daily->average_weight_g === null ? null : $this->math->format((string) $daily->average_weight_g),
            'anomalous_entry_count' => $daily->anomalous_entry_count,
            'last_entry' => $daily->lastEntry === null ? null : $this->entry($daily->lastEntry),
        ];
    }

    /** @return array<string, mixed> */
    public function entry(DailyWeighingEntry $entry): array
    {
        return [
            'id' => $entry->public_id,
            'occurred_at' => $entry->occurred_at->format('Y-m-d\TH:i:sP'),
            'mode' => $entry->mode,
            'weight_g' => $entry->weight_g === null ? null : $this->math->format((string) $entry->weight_g, 1),
            'bird_count' => $entry->bird_count,
            'total_weight_g' => $this->math->format((string) $entry->total_weight_g, 1),
            'average_weight_g' => $this->math->format((string) $entry->average_weight_g),
            'outside_expected_range' => $entry->outside_expected_range,
        ];
    }
}
