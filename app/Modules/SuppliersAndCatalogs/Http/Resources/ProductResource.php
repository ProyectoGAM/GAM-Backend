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
            'name' => $this->name,
            'kind' => $this->kind->value,
            'base_unit' => $this->base_unit->value,
            'stock_tracked' => $this->stock_tracked,
            'status' => $this->status->value,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
