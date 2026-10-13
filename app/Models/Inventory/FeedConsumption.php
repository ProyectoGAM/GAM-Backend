<?php

namespace App\Models\Inventory;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $flock_id
 * @property int $production_unit_id
 * @property int $flock_feeding_change_id
 * @property int $feed_recipe_id
 * @property string $mode
 * @property int $age_days
 * @property int $population
 * @property int $grams_per_bird
 * @property int $quantity_grams
 * @property CarbonImmutable $consumption_date
 * @property string $operation_id
 * @property array<string, mixed> $stage_snapshot
 * @property array<string, mixed> $census_snapshot
 * @property-read FeedConsumptionCorrection|null $latestCorrection
 */
class FeedConsumption extends Model
{
    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'consumption_date' => 'immutable_date', 'age_days' => 'integer',
            'population' => 'integer', 'grams_per_bird' => 'integer', 'quantity_grams' => 'integer',
            'stage_snapshot' => 'array', 'census_snapshot' => 'array',
        ];
    }

    /** @return HasMany<FeedConsumptionCorrection, $this> */
    public function corrections(): HasMany
    {
        return $this->hasMany(FeedConsumptionCorrection::class)->orderByDesc('id');
    }

    /** @return HasOne<FeedConsumptionCorrection, $this> */
    public function latestCorrection(): HasOne
    {
        return $this->hasOne(FeedConsumptionCorrection::class)->latestOfMany();
    }

    /** @return HasMany<FeedConsumptionMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(FeedConsumptionMovement::class)->orderBy('id');
    }

    /** @return array{recipe_id:int, quantity_grams:int} */
    public function effective(): array
    {
        $this->loadMissing('latestCorrection');
        $correction = $this->getRelation('latestCorrection');

        return [
            'recipe_id' => $correction instanceof FeedConsumptionCorrection ? $correction->feed_recipe_id : $this->feed_recipe_id,
            'quantity_grams' => $correction instanceof FeedConsumptionCorrection ? $correction->quantity_grams : $this->quantity_grams,
        ];
    }
}
