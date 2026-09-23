<?php

namespace App\Http\Resources\ManagementPlans;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PlanTemplateResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->resource;

        return $data;
    }
}
