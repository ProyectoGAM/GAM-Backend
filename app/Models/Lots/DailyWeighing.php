<?php

namespace App\Models\Lots;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $flock_id
 * @property CarbonImmutable $local_date
 * @property string|null $stage
 * @property string|null $min_weight_g
 * @property string|null $max_weight_g
 * @property int|null $reference_version
 * @property string|null $reference_source
 * @property int|null $reference_breed_id
 * @property int|null $reference_breed_version
 * @property int $version
 * @property int $represented_bird_count
 * @property string $total_weight_g
 * @property string|null $average_weight_g
 * @property int $anomalous_entry_count
 * @property-read Flock $flock
 * @property-read DailyWeighingEntry|null $lastEntry
 */
class DailyWeighing extends Model
{
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected static function booted(): void
    {
        static::creating(function (self $daily): void {
            $daily->public_id ??= (string) Str::ulid();
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
            'local_date' => 'immutable_date',
            'reference_version' => 'integer',
            'reference_breed_id' => 'integer',
            'reference_breed_version' => 'integer',
            'version' => 'integer',
            'represented_bird_count' => 'integer',
            'anomalous_entry_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Flock, $this> */
    public function flock(): BelongsTo
    {
        return $this->belongsTo(Flock::class);
    }

    /** @return HasMany<DailyWeighingEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(DailyWeighingEntry::class)->whereNull('deleted_at');
    }

    /** @return HasOne<DailyWeighingEntry, $this> */
    public function lastEntry(): HasOne
    {
        return $this->hasOne(DailyWeighingEntry::class)->ofMany(['occurred_at' => 'MAX', 'id' => 'MAX'], static function (Builder $query): void {
            $query->whereNull('deleted_at');
        });
    }
}
