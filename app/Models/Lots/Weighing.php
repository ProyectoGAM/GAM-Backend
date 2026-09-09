<?php

namespace App\Models\Lots;

use Carbon\CarbonImmutable;
use Database\Factories\Lots\WeighingFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $flock_id
 * @property int $poultry_house_id
 * @property int $production_unit_id
 * @property string $mode
 * @property CarbonImmutable $occurred_at
 * @property string $captured_unit
 * @property string|null $notes
 * @property string|null $stage
 * @property string|null $min_weight_g
 * @property string|null $max_weight_g
 * @property int|null $reference_adult_from_week
 * @property string|null $reference_chick_min_weight_g
 * @property string|null $reference_chick_max_weight_g
 * @property string|null $reference_adult_min_weight_g
 * @property string|null $reference_adult_max_weight_g
 * @property string|null $reference_unit
 * @property int|null $reference_version
 * @property int $represented_bird_count
 * @property string $total_weight_g
 * @property string $average_weight_g
 * @property bool $outside_expected_range
 * @property int $version
 * @property int $created_by
 * @property-read Flock $flock
 * @property-read Collection<int, WeighingMeasurement> $measurements
 */
class Weighing extends Model
{
    /** Conserva el instante aunque PostgreSQL use una zona horaria distinta de UTC. */
    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @use HasFactory<WeighingFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $weighing): void {
            $weighing->public_id ??= (string) Str::ulid();
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
            'occurred_at' => 'immutable_datetime',
            'reference_adult_from_week' => 'integer',
            'reference_version' => 'integer',
            'represented_bird_count' => 'integer',
            'outside_expected_range' => 'boolean',
            'version' => 'integer',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Flock, $this> */
    public function flock(): BelongsTo
    {
        return $this->belongsTo(Flock::class);
    }

    /** @return HasMany<WeighingMeasurement, $this> */
    public function measurements(): HasMany
    {
        return $this->hasMany(WeighingMeasurement::class)->orderBy('position');
    }
}
