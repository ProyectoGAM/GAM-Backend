<?php

namespace App\Modules\ReportingAndAnalytics\Http\Resources;

use App\Modules\ReportingAndAnalytics\Application\Data\ReportResultData;
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
