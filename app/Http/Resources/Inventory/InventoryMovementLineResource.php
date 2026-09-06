<?php

namespace App\Http\Resources\Inventory;

use App\Http\Resources\SuppliersAndCatalogs\ProductResource;
use App\Models\Inventory\InventoryMovementLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InventoryMovementLine */
final class InventoryMovementLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'product' => ProductResource::make($this->whenLoaded('product')),
            'product_id' => (int) $this->product_id,
            'stock_location_id' => (int) $this->stock_location_id,
            'unit' => $this->unit,
            'physical_delta' => (string) $this->on_hand_delta,
        ];
    }
}
