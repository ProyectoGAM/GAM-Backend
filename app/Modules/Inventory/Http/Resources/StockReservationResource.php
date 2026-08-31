<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Models\Inventory\StockReservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockReservation */
final class StockReservationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'estado' => $this->status->value,
            'tipo_referencia' => $this->reference_type,
            'referencia_id' => $this->reference_id,
            'lineas' => StockReservationLineResource::collection($this->whenLoaded('lines')),
            'creado_por' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
