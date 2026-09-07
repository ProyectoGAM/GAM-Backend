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
            'name' => $this->name,
            'description' => $this->description,
            'supplier' => [
                'id' => $this->supplier_id,
                'name_at_registration' => $this->supplier_name_snapshot,
            ],
            'created_by' => ['id' => $this->created_by, 'name_at_registration' => $this->created_by_name],
            'created_at' => $this->created_at->utc()->toIso8601String(),
            'operation_id' => $this->operation_id,
        ];
    }
}
