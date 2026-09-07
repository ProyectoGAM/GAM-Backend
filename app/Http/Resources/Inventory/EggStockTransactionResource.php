<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class EggStockTransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'production_unit_id' => $this->production_unit_id,
            'type' => $this->type,
            'quantity' => (int) $this->quantity,
            'occurred_at' => $this->occurred_at,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'status' => $this->status,
            'version' => (int) $this->version,
            'reference' => $this->reference_type === null ? null : ['type' => $this->reference_type, 'id' => $this->reference_id],
            'inventory_references' => $this->inventory_references ?? [],
            'balance' => $this->when(isset($this->balance), $this->balance),
            'revisions' => EggStockTransactionRevisionResource::collection($this->whenLoaded('revisions')),
        ];
    }
}
