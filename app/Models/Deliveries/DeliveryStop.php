<?php

namespace App\Models\Deliveries;

use App\Enums\Deliveries\DeliveryStopStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'delivery_id', 'client_reference', 'client_name', 'address', 'latitude', 'longitude',
    'sequence', 'status', 'delivered_quantity', 'items', 'visit_reason', 'notes', 'idempotency_key',
    'request_hash', 'visited_at',
])]
class DeliveryStop extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'status' => DeliveryStopStatus::class,
            'delivered_quantity' => 'integer',
            'items' => 'array',
            'sequence' => 'integer',
            'visited_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Delivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }
}
