<?php

namespace App\Http\Resources\Inventory;

use App\Http\Resources\FarmStructure\ProductionUnitResource;
use App\Models\Inventory\StockLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockLocation */
final class StockLocationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'name' => $this->name,
            'status' => $this->status->value,
            'production_unit' => ProductionUnitResource::make($this->whenLoaded('productionUnit')),
            'poultry_house_id' => $this->poultry_house_id === null ? null : (int) $this->poultry_house_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
