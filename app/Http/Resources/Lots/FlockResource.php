<?php

namespace App\Http\Resources\Lots;

use Illuminate\Http\Request;

final class FlockResource extends FlockSnapshotResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->resource;

        return [...parent::toArray($request), 'is_grouped' => $data['is_grouped']];
    }
}
