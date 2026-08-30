<?php

namespace App\Modules\FarmStructure\Http\Resources;

use App\Models\FarmStructure\Maintenance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Maintenance */
final class MaintenanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'galpon_id' => $this->poultry_house_id,
            'fecha_mantenimiento' => $this->maintenance_date->toDateString(),
            'descripcion' => $this->description,
            'costo' => ['importe' => $this->cost->amount(), 'moneda' => $this->cost->currency()],
            'responsable' => ['id' => $this->responsible_user_id, 'nombre' => $this->responsible_name],
            'estado' => $this->status->value,
            'version' => $this->version,
            'motivo_cancelacion' => $this->cancellation_reason,
            'cancelado_en' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
