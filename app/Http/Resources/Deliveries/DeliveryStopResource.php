<?php

namespace App\Http\Resources\Deliveries;

use App\ValueObjects\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DeliveryStopResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_reference' => $this->client_reference,
            'client_name' => $this->client_name,
            'address' => $this->address,
            'latitude' => (string) $this->latitude,
            'longitude' => (string) $this->longitude,
            'sequence' => (int) $this->sequence,
            'status' => $this->status->value,
            'delivered_quantity' => (int) $this->delivered_quantity,
            'items' => $this->items,
            'total_amount' => $this->total_amount,
            'currency' => Money::CURRENCY,
            'visit_reason' => $this->visit_reason,
            'notes' => $this->notes,
            'visited_at' => $this->visited_at,
        ];
    }
}
