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
            'localidad_id' => $this->locality_id,
            'nombre' => $this->name,
            'latitud' => $this->latitude,
            'longitud' => $this->longitude,
            'estado' => $this->status->value,
            'galpones_count' => $this->whenCounted('poultryHouses'),
            'localidad' => LocalityResource::make($this->whenLoaded('locality')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
