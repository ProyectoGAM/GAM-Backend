<?php

namespace App\Http\Resources\ReportingAndAnalytics;

use App\DTO\ReportingAndAnalytics\ReportResultData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReportResultData */
final class ReportResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
