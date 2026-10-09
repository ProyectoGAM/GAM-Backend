<?php

namespace App\Http\Resources\Lots;

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
            'name' => $data['name'] ?? null,
            'status' => $data['status'] ?? null,
            'version' => $data['version'] ?? null,
            ...(array_key_exists('range_overrides', $data) ? ['range_overrides' => $data['range_overrides']] : []),
            ...(array_key_exists('expected_ranges', $data) ? ['expected_ranges' => $data['expected_ranges']] : []),
        ];
    }
}
