<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Models\Inventory\StockReservationLine;
use App\Modules\SuppliersAndCatalogs\Http\Resources\ProductResource;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockReservationLine */
final class StockReservationLineResource extends JsonResource
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
            'reserved_quantity' => (string) $this->reserved_quantity,
            'released_quantity' => (string) $this->released_quantity,
            'consumed_quantity' => (string) $this->consumed_quantity,
            'remaining_quantity' => (string) BigDecimal::of((string) $this->reserved_quantity)->minus((string) $this->released_quantity)->minus((string) $this->consumed_quantity)->toScale(6),
        ];
    }
}
