<?php

namespace App\Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class EggStockTransactionRevisionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'accion' => $this->action,
            'antes' => $this->before,
            'despues' => $this->after,
            'motivo_correccion' => $this->correction_reason,
            'operacion_id' => $this->operation_id,
            'realizado_por' => $this->created_by,
            'realizado_en' => $this->created_at,
        ];
    }
}
