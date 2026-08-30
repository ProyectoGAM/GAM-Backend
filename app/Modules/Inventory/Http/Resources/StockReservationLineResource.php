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
            'producto' => ProductResource::make($this->whenLoaded('product')),
            'producto_id' => (int) $this->product_id,
            'ubicacion_stock_id' => (int) $this->stock_location_id,
            'unidad' => $this->unit,
            'cantidad_reservada' => (string) $this->reserved_quantity,
            'cantidad_liberada' => (string) $this->released_quantity,
            'cantidad_consumida' => (string) $this->consumed_quantity,
            'cantidad_restante' => (string) BigDecimal::of((string) $this->reserved_quantity)->minus((string) $this->released_quantity)->minus((string) $this->consumed_quantity)->toScale(6),
        ];
    }
}
