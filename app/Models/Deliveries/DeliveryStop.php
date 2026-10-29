<?php

namespace App\Models\Deliveries;

use App\Enums\Deliveries\DeliveryStopStatus;
use App\Models\CustomersAndAccounts\CustomerAccountMovement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $delivery_id
 * @property int|null $customer_id
 * @property DeliveryStopStatus $status
 * @property string $accounting_status
 * @property string|null $total_amount
 * @property array|null $items
 * @property CarbonImmutable|null $visited_at
 */
#[Fillable([
    'delivery_id', 'client_reference', 'client_name', 'address', 'latitude', 'longitude',
    'sequence', 'status', 'delivered_quantity', 'items', 'visit_reason', 'notes', 'idempotency_key',
    'request_hash', 'visited_at', 'total_amount', 'customer_id', 'accounting_status',
])]
class DeliveryStop extends Model
{
    /** @return array<string, string> */
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'status' => DeliveryStopStatus::class,
            'delivered_quantity' => 'integer',
            'items' => 'array',
            'total_amount' => 'decimal:3',
            'sequence' => 'integer',
            'visited_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Delivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /** @return HasMany<CustomerAccountMovement, $this> */
    public function accountMovements(): HasMany
    {
        return $this->hasMany(CustomerAccountMovement::class);
    }
}
