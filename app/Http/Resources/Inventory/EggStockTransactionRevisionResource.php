<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class EggStockTransactionRevisionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'action' => $this->action,
            'before' => $this->before,
            'after' => $this->after,
            'correction_reason' => $this->correction_reason,
            'operation_id' => $this->operation_id,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
        ];
    }
}
