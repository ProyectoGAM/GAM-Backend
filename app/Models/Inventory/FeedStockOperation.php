<?php

namespace App\Models\Inventory;

use Carbon\CarbonImmutable;
use Database\Factories\Inventory\FeedStockOperationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property 'manufacture'|'cancellation'|'ingredient_adjustment'|'feed_adjustment' $kind
 * @property string $operation_id
 * @property string $request_hash
 * @property int $production_unit_id
 * @property int|null $feed_recipe_id
 * @property int|null $inventory_movement_id
 * @property int $quantity_grams
 * @property string $balance_before
 * @property string $balance_after
 * @property string $delta_grams
 * @property CarbonImmutable $occurred_at
 * @property array<string, mixed> $snapshot
 */
class FeedStockOperation extends Model
{
    /** @use HasFactory<FeedStockOperationFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array', 'quantity_grams' => 'integer', 'factor' => 'integer',
            'balance_before' => 'decimal:6', 'balance_after' => 'decimal:6',
            'delta_grams' => 'decimal:6', 'occurred_at' => 'immutable_datetime',
        ];
    }

    /**
     * La cancelación agrega otra operación; no modifica la elaboración original.
     *
     * @return HasOne<FeedStockOperation, $this>
     */
    public function cancellation(): HasOne
    {
        return $this->hasOne(self::class, 'cancels_operation_id');
    }
}
