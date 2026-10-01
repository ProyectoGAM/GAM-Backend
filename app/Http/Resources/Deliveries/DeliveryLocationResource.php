<?php

namespace App\Http\Resources\Deliveries;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DeliveryLocationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->client_event_id,
            'latitude' => (string) $this->latitude,
            'longitude' => (string) $this->longitude,
            'accuracy' => $this->accuracy === null ? null : (float) $this->accuracy,
            'speed' => $this->speed === null ? null : (float) $this->speed,
            'captured_at' => $this->captured_at,
            'received_at' => $this->received_at,
        ];
    }
}
