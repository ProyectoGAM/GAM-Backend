<?php

namespace App\Http\Resources\Lots;

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
            'flock_id' => $data['flock_id'] ?? null,
            'plan_activity_id' => $data['plan_activity_id'] ?? null,
            'origin_operation_id' => $data['origin_operation_id'] ?? null,
            'poultry_house_id' => $data['poultry_house_id'] ?? null,
            'production_unit_id' => $data['production_unit_id'] ?? null,
            'quantity' => $data['quantity'] ?? null,
            'occurred_at' => $data['occurred_at'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => $data['status'] ?? null,
            'version' => $data['version'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ];
    }
}
