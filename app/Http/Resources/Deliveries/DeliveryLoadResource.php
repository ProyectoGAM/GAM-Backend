<?php

namespace App\Http\Resources\Deliveries;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DeliveryLoadResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'idempotency_key' => $this->idempotency_key,
            'quantity' => (int) $this->quantity,
            'production_unit' => $this->when($this->relationLoaded('productionUnit'), fn () => $this->productionUnit === null ? null : [
                'id' => $this->productionUnit->id, 'name' => $this->productionUnit->name,
            ]),
            // Las cargas anteriores a la migración siguen representando huevos sueltos.
            'items' => $this->items ?? [[
                'unit' => 'huevo', 'label' => 'Huevos', 'category' => 'huevos', 'amount' => (string) $this->quantity,
                'eggs_per_unit' => 1, 'eggs' => (int) $this->quantity,
            ]],
            'type' => $this->type,
            'created_at' => $this->created_at,
        ];
    }
}
