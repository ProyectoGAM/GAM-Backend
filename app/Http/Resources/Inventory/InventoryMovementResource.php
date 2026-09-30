<?php

namespace App\Http\Resources\Inventory;

use App\Http\Resources\SuppliersAndCatalogs\SupplierResource;
use App\Models\Inventory\InventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InventoryMovement */
final class InventoryMovementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'operation_id' => $this->operation_id,
            'type' => $this->type->value,
            'supplier' => SupplierResource::make($this->whenLoaded('supplier')),
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'reason' => $this->reason,
            'occurred_at' => $this->occurred_at,
            'created_by' => $this->created_by,
            'reverses_movement_id' => $this->reverses_movement_id,
            'lines' => InventoryMovementLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at,
        ];
    }
}
