<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Models\Inventory\InventoryMovementLine;
use App\Modules\SuppliersAndCatalogs\Http\Resources\ProductResource;
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
            'on_hand_delta' => (string) $this->on_hand_delta,
            'reserved_delta' => (string) $this->reserved_delta,
        ];
    }
}
