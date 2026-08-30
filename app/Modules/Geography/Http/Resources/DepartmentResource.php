<?php

namespace App\Modules\Geography\Http\Resources;

use App\Models\Geography\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Department */
final class DepartmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'nombre' => $this->name,
            'localidades_count' => $this->whenCounted('localities'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
