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
            'producto' => ProductResource::make($this->whenLoaded('product')),
            'producto_id' => (int) $this->product_id,
            'ubicacion_stock_id' => (int) $this->stock_location_id,
            'unidad' => $this->unit,
            'variacion_fisica' => (string) $this->on_hand_delta,
        ];
    }
}
