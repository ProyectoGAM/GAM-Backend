<?php

namespace App\Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class EggStockTransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'unidad_productiva_id' => $this->production_unit_id,
            'tipo' => $this->type,
            'cantidad' => (int) $this->quantity,
            'ocurrido_en' => $this->occurred_at,
            'motivo' => $this->reason,
            'observaciones' => $this->notes,
            'estado' => $this->status,
            'version' => (int) $this->version,
            'referencia' => $this->reference_type === null ? null : ['tipo' => $this->reference_type, 'id' => $this->reference_id],
            'referencias_inventario' => $this->inventory_references ?? [],
            'saldo' => $this->when(isset($this->saldo), $this->saldo),
            'revisiones' => EggStockTransactionRevisionResource::collection($this->whenLoaded('revisions')),
        ];
    }
}
