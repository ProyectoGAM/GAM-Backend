<?php

namespace App\Modules\Lots\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class EggCollectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->resource;

        return [
            'id' => $data['public_id'] ?? null,
            'lote_id' => $data['flock_id'] ?? null,
            'galpon_id' => $data['poultry_house_id'] ?? null,
            'unidad_productiva_id' => $data['production_unit_id'] ?? null,
            'cantidad' => $data['quantity'] ?? null,
            'ocurrido_en' => $data['occurred_at'] ?? null,
            'observaciones' => $data['notes'] ?? null,
            'estado' => $data['status'] ?? null,
            'version' => $data['version'] ?? null,
            'registrado_por' => $data['created_by'] ?? null,
        ];
    }
}
