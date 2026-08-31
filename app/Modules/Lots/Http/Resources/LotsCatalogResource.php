<?php

namespace App\Modules\Lots\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LotsCatalogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->resource;

        return [
            'id' => $data['id'] ?? null,
            'nombre' => $data['name'] ?? null,
            'estado' => $data['status'] ?? null,
            'version' => $data['version'] ?? null,
        ];
    }
}
