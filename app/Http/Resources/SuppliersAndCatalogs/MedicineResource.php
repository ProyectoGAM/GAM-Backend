<?php

namespace App\Http\Resources\SuppliersAndCatalogs;

use App\Models\SuppliersAndCatalogs\Medicine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Medicine */
final class MedicineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'nombre' => $this->name,
            'descripcion' => $this->description,
            'proveedor' => [
                'id' => $this->supplier_id,
                'nombre_al_registrar' => $this->supplier_name_snapshot,
            ],
            'registrado_por' => ['id' => $this->created_by, 'nombre_al_registrar' => $this->created_by_name],
            'registrado_en' => $this->created_at->utc()->toIso8601String(),
            'id_operacion' => $this->operation_id,
        ];
    }
}
