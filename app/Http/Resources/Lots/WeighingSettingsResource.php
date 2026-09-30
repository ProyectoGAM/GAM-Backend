<?php

namespace App\Http\Resources\Lots;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WeighingSettingsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->resource;

        return $data;
    }
}
