<?php

namespace App\Http\Resources\Lots;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MortalityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->resource;

        return [
            'id' => $data['public_id'] ?? null,
            'flock_id' => $data['flock_id'] ?? null,
            'poultry_house_id' => $data['poultry_house_id'] ?? null,
            'production_unit_id' => $data['production_unit_id'] ?? null,
            'mortality_category_id' => $data['mortality_category_id'] ?? null,
            'quantity' => $data['quantity'] ?? null,
            'occurred_at' => $data['occurred_at'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => $data['status'] ?? null,
            'version' => $data['version'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ];
    }
}
