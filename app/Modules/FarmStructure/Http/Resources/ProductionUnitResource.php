<?php

namespace App\Modules\FarmStructure\Http\Resources;

use App\Models\FarmStructure\ProductionUnit;
use App\Modules\Geography\Http\Resources\LocalityResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductionUnit */
final class ProductionUnitResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'locality_id' => $this->locality_id,
            'name' => $this->name,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'status' => $this->status->value,
            'poultry_houses_count' => $this->whenCounted('poultryHouses'),
            'locality' => LocalityResource::make($this->whenLoaded('locality')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
