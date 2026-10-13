<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;

/** Los movimientos son compensaciones anexadas; nunca reemplazan los débitos originales. */
class FeedConsumptionMovement extends Model
{
    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['delta_grams' => 'integer', 'balance_before' => 'integer', 'balance_after' => 'integer'];
    }
}
