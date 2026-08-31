<?php

namespace App\Modules\FarmStructure\Application\Data;

use App\Models\FarmStructure\Maintenance;

final readonly class MaintenanceSnapshot
{
    /**
     * Serializa únicamente datos permitidos sin consultar relaciones.
     *
     * @return array<string, mixed>
     */
    public static function from(Maintenance $maintenance): array
    {
        return [
            'poultry_house_id' => $maintenance->poultry_house_id,
            'maintenance_date' => $maintenance->maintenance_date->toDateString(),
            'description' => $maintenance->description,
            'cost' => $maintenance->cost->toArray(),
            'responsible_user_id' => $maintenance->responsible_user_id,
            'responsible_name' => $maintenance->responsible_name,
            'status' => $maintenance->status->value,
            'version' => $maintenance->version,
            'cancellation_reason' => $maintenance->cancellation_reason,
            'cancelled_at' => $maintenance->cancelled_at?->toIso8601String(),
        ];
    }
}
