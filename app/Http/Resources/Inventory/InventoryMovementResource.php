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
            'id_operacion' => $this->operation_id,
            'tipo' => $this->type->value,
            'proveedor' => SupplierResource::make($this->whenLoaded('supplier')),
            'tipo_referencia' => $this->reference_type,
            'referencia_id' => $this->reference_id,
            'motivo' => $this->reason,
            'ocurrido_en' => $this->occurred_at,
            'creado_por' => $this->created_by,
            'revierte_movimiento_id' => $this->reverses_movement_id,
            'lineas' => InventoryMovementLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at,
        ];
    }
}
