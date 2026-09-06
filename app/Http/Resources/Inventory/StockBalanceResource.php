<?php

namespace App\Http\Resources\Inventory;

use App\Http\Resources\SuppliersAndCatalogs\ProductResource;
use App\Models\Inventory\StockBalance;
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
            'available_quantity' => (string) $this->available_quantity,
            'minimum_quantity' => (string) $this->minimum_quantity,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
