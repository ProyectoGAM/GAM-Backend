<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $feed_consumption_id
 * @property int $feed_recipe_id
 * @property int $quantity_grams
 * @property string $reason
 * @property string $operation_id
 * @property string $request_hash
 * @property array<string, mixed> $before_snapshot
 */
class FeedConsumptionCorrection extends Model
{
    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity_grams' => 'integer', 'before_snapshot' => 'array'];
    }
}
