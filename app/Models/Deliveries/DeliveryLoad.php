<?php

namespace App\Models\Deliveries;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['delivery_id', 'idempotency_key', 'quantity', 'items', 'request_hash', 'type', 'created_by'])]
class DeliveryLoad extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'items' => 'array'];
    }

    /** @return BelongsTo<Delivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
