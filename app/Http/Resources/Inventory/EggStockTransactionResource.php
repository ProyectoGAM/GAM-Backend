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
            'balance_before' => $this->balance_before === null ? null : (int) $this->balance_before,
            'counted_quantity' => $this->counted_quantity === null ? null : (int) $this->counted_quantity,
            'difference' => $this->difference === null ? null : (int) $this->difference,
            'occurred_at' => $this->occurred_at,
            'reason' => $this->reason,
            'actor' => $this->whenLoaded('creator', fn (): array => [
                'id' => (int) $this->creator->getKey(),
                'name' => $this->creator->name,
            ]),
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
