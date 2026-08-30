<?php

namespace App\Modules\SuppliersAndCatalogs\Http\Resources;

use App\Models\SuppliersAndCatalogs\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
final class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'sku' => $this->sku,
            'nombre' => $this->name,
            'tipo' => $this->kind->value,
            'unidad_base' => $this->base_unit->value,
            'controla_stock' => $this->stock_tracked,
            'estado' => $this->status->value,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
