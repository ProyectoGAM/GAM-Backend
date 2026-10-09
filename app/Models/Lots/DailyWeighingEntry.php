<?php

namespace App\Models\Lots;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $daily_weighing_id
 * @property string $mode
 * @property string|null $weight_g
 * @property int $bird_count
 * @property string $total_weight_g
 * @property string $average_weight_g
 * @property bool $outside_expected_range
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable|null $deleted_at
 */
class DailyWeighingEntry extends Model
{
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            $entry->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'bird_count' => 'integer',
            'outside_expected_range' => 'boolean',
            'occurred_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<DailyWeighing, $this> */
    public function dailyWeighing(): BelongsTo
    {
        return $this->belongsTo(DailyWeighing::class);
    }
}
