<?php

namespace App\Http\Resources\SuppliersAndCatalogs;

use App\Models\SuppliersAndCatalogs\Vaccine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Vaccine */
class VaccineResource extends JsonResource
{
    /**
     * Convierte el recurso en una representación pública.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'sku' => $this->product->sku,
            'producto_id' => $this->product_id,
            'unidad_base' => $this->product->base_unit->value,
            'estado' => $this->product->status->value,
            'nombre' => $this->product->name,
            'descripcion' => $this->description,
            'detalles' => $this->details,
            'proveedor' => [
                'id' => $this->supplier_id,
                'nombre' => $this->supplier->name,
                'nombre_al_asociar' => $this->supplier_name_snapshot,
            ],
            'creado_por' => ['id' => $this->created_by, 'nombre_al_crear' => $this->created_by_name],
            'creado_en' => $this->created_at->utc()->toIso8601String(),
            'actualizado_en' => $this->updated_at->utc()->toIso8601String(),
            'operacion_id' => $this->operation_id,
        ];
    }
}
