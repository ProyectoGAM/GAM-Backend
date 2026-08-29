<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Models\Inventory\InventoryMovement;
use App\Modules\SuppliersAndCatalogs\Http\Resources\SupplierResource;
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
            'stock_reservation_id' => $this->stock_reservation_id,
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
