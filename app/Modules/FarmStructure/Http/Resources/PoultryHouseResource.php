<?php

namespace App\Modules\FarmStructure\Http\Resources;

use App\Models\FarmStructure\PoultryHouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PoultryHouse */
final class PoultryHouseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'production_unit_id' => $this->production_unit_id,
            'name' => $this->name,
            'bird_capacity' => $this->bird_capacity,
            'status' => $this->status->value,
            'production_unit' => ProductionUnitResource::make($this->whenLoaded('productionUnit')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
