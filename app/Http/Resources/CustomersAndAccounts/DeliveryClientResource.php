<?php

namespace App\Http\Resources\CustomersAndAccounts;

use App\Enums\CustomersAndAccounts\CustomerStatus;
use App\Models\CustomersAndAccounts\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Customer */
final class DeliveryClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->public_id, 'name' => $this->displayName(), 'address' => $this->address,
            'latitude' => $this->latitude, 'longitude' => $this->longitude, 'status' => $this->status->value,
            'ready_for_delivery' => $this->status === CustomerStatus::Active && filled($this->address) && $this->latitude !== null && $this->longitude !== null,
            'delivery_notes' => $this->delivery_notes, 'visit_frequency' => $this->visit_frequency,
            'account' => new CustomerAccountResource($this->whenLoaded('account')),
            'last_delivery' => $this->when($this->relationLoaded('lastDelivery'), fn () => $this->lastDelivery === null ? null : [
                'id' => $this->lastDelivery->id, 'delivery_id' => $this->lastDelivery->delivery?->public_id,
                'visited_at' => $this->lastDelivery->visited_at, 'delivered_quantity' => $this->lastDelivery->delivered_quantity,
                'items' => $this->lastDelivery->items, 'total_amount' => $this->lastDelivery->total_amount])];
    }
}
