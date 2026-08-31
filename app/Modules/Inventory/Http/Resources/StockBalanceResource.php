<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Models\Inventory\StockBalance;
use App\Modules\SuppliersAndCatalogs\Http\Resources\ProductResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockBalance */
final class StockBalanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'producto_id' => (int) $this->product_id,
            'ubicacion_stock_id' => (int) $this->stock_location_id,
            'producto' => ProductResource::make($this->whenLoaded('product')),
            'ubicacion_stock' => StockLocationResource::make($this->whenLoaded('stockLocation')),
            'cantidad_fisica' => (string) $this->on_hand_quantity,
            'cantidad_reservada' => (string) $this->reserved_quantity,
            'cantidad_disponible' => (string) $this->available_quantity,
            'cantidad_minima' => (string) $this->minimum_quantity,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
