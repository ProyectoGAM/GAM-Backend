<?php

namespace App\Modules\Geography\Http\Resources;

use App\Models\Geography\Locality;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Locality */
final class LocalityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'department_id' => $this->department_id,
            'name' => $this->name,
            'department' => DepartmentResource::make($this->whenLoaded('department')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
