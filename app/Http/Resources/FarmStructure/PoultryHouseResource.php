<?php

namespace App\Http\Resources\FarmStructure;

use App\Enums\FarmStructure\PoultryHouseType;
use App\Models\FarmStructure\PoultryHouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PoultryHouse */
final class PoultryHouseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $hasOccupancy = array_key_exists('current_occupancy', $this->resource->getAttributes());
        $isFeed = $this->type === PoultryHouseType::Feed;
        $excess = $isFeed ? 0 : max(0, (int) $this->resource->getAttribute('current_occupancy') - (int) $this->bird_capacity);

        return [
            'id' => (int) $this->getKey(),
            'production_unit_id' => $this->production_unit_id,
            'name' => $this->name,
            'type' => $this->type->value,
            'bird_capacity' => $this->bird_capacity,
            'current_occupancy' => $this->type === PoultryHouseType::Feed
                ? null
                : $this->when(
                    array_key_exists('current_occupancy', $this->resource->getAttributes()),
                    fn (): int => (int) $this->resource->getAttribute('current_occupancy'),
                ),
            'status' => $this->status->value,
            'capacity_exceeded' => $this->when($isFeed || $hasOccupancy, $excess > 0),
            'excess_quantity' => $this->when($isFeed || $hasOccupancy, $isFeed ? null : $excess),
            'capacity_alert' => $this->when(
                $isFeed || $hasOccupancy,
                $excess > 0 ? "La ocupación del galpón supera su capacidad máxima. Aves excedentes: {$excess}." : null,
            ),
            'production_unit' => ProductionUnitResource::make($this->whenLoaded('productionUnit')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
