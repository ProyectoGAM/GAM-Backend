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
            'poultry_house_id' => $this->poultry_house_id,
            'maintenance_date' => $this->maintenance_date->toDateString(),
            'description' => $this->description,
            'cost' => $this->cost->toArray(),
            'responsible' => ['id' => $this->responsible_user_id, 'name' => $this->responsible_name],
            'status' => $this->status->value,
            'version' => $this->version,
            'cancellation_reason' => $this->cancellation_reason,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
