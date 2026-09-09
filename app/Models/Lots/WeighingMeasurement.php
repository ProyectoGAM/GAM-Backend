<?php

namespace App\Models\Lots;

use Database\Factories\Lots\WeighingMeasurementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $weighing_id
 * @property int $position
 * @property string|null $weight_g
 * @property string|null $total_weight_g
 * @property int|null $bird_count
 * @property string|null $average_weight_g
 * @property bool $outside_expected_range
 * @property-read Weighing $weighing
 */
class WeighingMeasurement extends Model
{
    /** @use HasFactory<WeighingMeasurementFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'bird_count' => 'integer',
            'outside_expected_range' => 'boolean',
        ];
    }

    /** @return BelongsTo<Weighing, $this> */
    public function weighing(): BelongsTo
    {
        return $this->belongsTo(Weighing::class);
    }
}
