<?php

namespace App\Http\Resources\Inventory;

use App\Models\SuppliersAndCatalogs\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
final class AllowedStockProductResource extends JsonResource
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
            'available_quantity' => (string) ($this->getAttribute('available_quantity') ?? '0.000000'),
        ];
    }
}
