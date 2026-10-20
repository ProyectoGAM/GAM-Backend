<?php

namespace App\Models\Inventory;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $flock_id
 * @property int $flock_plan_revision_id
 * @property string $operation_id
 * @property CarbonImmutable $effective_date
 * @property CarbonImmutable $baseline_date
 * @property string $mode
 * @property int $feed_recipe_id
 * @property int $grams_per_bird
 * @property int|null $daily_quantity_grams
 * @property int $anchor_day
 * @property array<string, mixed> $stage_snapshot
 * @property list<array<string, mixed>> $stages
 * @property int|null $created_by
 */
class FlockFeedingChange extends Model
{
    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_date' => 'immutable_date', 'baseline_date' => 'immutable_date',
            'grams_per_bird' => 'integer', 'daily_quantity_grams' => 'integer',
            'anchor_day' => 'integer', 'stages' => 'array', 'stage_snapshot' => 'array',
        ];
    }
}
