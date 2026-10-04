<?php

namespace App\Http\Resources\FarmStructure;

use App\Http\Resources\Geography\LocalityResource;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Geography\Locality;
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
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'status' => $this->status->value,
            'poultry_houses_count' => $this->whenCounted('poultryHouses'),
            'locality' => $this->whenLoaded(
                'locality',
                static fn (?Locality $locality): ?LocalityResource => $locality === null
                    ? null
                    : LocalityResource::make($locality),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
