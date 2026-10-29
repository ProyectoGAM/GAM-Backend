<?php

namespace App\Http\Resources\CustomersAndAccounts;

use App\Models\CustomersAndAccounts\CustomerAccountMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CustomerAccountMovement */
final class CustomerMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->public_id, 'type' => $this->type->value, 'sequence' => $this->sequence,
            'amount' => $this->amount, 'balance_before' => $this->balance_before, 'balance_after' => $this->balance_after,
            'currency' => 'UYU', 'customer_name' => $this->customer_name, 'customer_id' => $this->whenLoaded('account', fn () => $this->account->customer->public_id), 'reason' => $this->reason, 'actor' => ['name' => $this->actor_name],
            'payment_method' => $this->payment_method, 'payment_reference' => $this->payment_reference,
            'occurred_at' => $this->occurred_at->utc()->toIso8601String(), 'created_at' => $this->created_at->utc()->toIso8601String(),
            'operation_id' => $this->whenLoaded('operation', fn () => $this->operation->operation_id),
            'delivery_id' => $this->whenLoaded('delivery', fn () => $this->delivery?->public_id),
            'delivery_stop_id' => $this->delivery_stop_id,
            'reversal_id' => $this->whenLoaded('reversal', fn () => $this->reversal?->public_id),
            'reverses_movement_id' => $this->whenLoaded('original', fn () => $this->original?->public_id)];
    }
}
