<?php

namespace App\Modules\Lots\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FlockResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->resource;

        return [
            'id' => $data['public_id'] ?? null,
            'codigo' => $data['code'] ?? null,
            'raza_id' => $data['breed_id'] ?? null,
            'proveedor_id' => $data['supplier_id'] ?? null,
            'proveedor_nombre' => $data['supplier_name'] ?? null,
            'origen' => $data['origin'] ?? null,
            'galpon_id' => $data['poultry_house_id'] ?? null,
            'unidad_productiva_id' => $data['production_unit_id'] ?? null,
            'cantidad_inicial' => $data['initial_quantity'] ?? null,
            'cantidad_viva' => $data['current_quantity'] ?? null,
            'fecha_ingreso' => $data['entry_date'] ?? null,
            'establecido_en' => $data['established_at'] ?? null,
            'edad_dias' => $data['age_days'] ?? null,
            'semana_actual' => $data['current_week'] ?? null,
            'estado' => $data['status'] ?? null,
            'version' => $data['version'] ?? null,
            'observaciones' => $data['notes'] ?? null,
            'finalizado_en' => $data['finalized_at'] ?? null,
            'motivo_finalizacion' => $data['finalization_reason'] ?? null,
        ];
    }
}
