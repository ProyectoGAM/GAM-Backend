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
            'product_id' => (int) $this->product_id,
            'stock_location_id' => (int) $this->stock_location_id,
            'product' => ProductResource::make($this->whenLoaded('product')),
            'stock_location' => StockLocationResource::make($this->whenLoaded('stockLocation')),
            'on_hand_quantity' => (string) $this->on_hand_quantity,
            'reserved_quantity' => (string) $this->reserved_quantity,
            'available_quantity' => (string) $this->available_quantity,
            'minimum_quantity' => (string) $this->minimum_quantity,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
