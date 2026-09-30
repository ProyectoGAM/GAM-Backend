<?php

namespace App\Http\Resources\Lots;

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
            'code' => $data['code'] ?? null,
            'breed_id' => $data['breed_id'] ?? null,
            'supplier_id' => $data['supplier_id'] ?? null,
            'supplier_name' => $data['supplier_name'] ?? null,
            'origin' => $data['origin'] ?? null,
            'poultry_house_id' => $data['poultry_house_id'] ?? null,
            'production_unit_id' => $data['production_unit_id'] ?? null,
            'initial_quantity' => $data['initial_quantity'] ?? null,
            'current_quantity' => $data['current_quantity'] ?? null,
            'entry_date' => $data['entry_date'] ?? null,
            'established_at' => $data['established_at'] ?? null,
            'age_days' => $data['age_days'] ?? null,
            'current_week' => $data['current_week'] ?? null,
            'status' => $data['status'] ?? null,
            'version' => $data['version'] ?? null,
            'notes' => $data['notes'] ?? null,
            'finalized_at' => $data['finalized_at'] ?? null,
            'finalization_reason' => $data['finalization_reason'] ?? null,
        ];
    }
}
