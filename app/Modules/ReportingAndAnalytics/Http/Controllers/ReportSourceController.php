<?php

namespace App\Modules\ReportingAndAnalytics\Http\Controllers;

use App\Models\User;
use App\Modules\ReportingAndAnalytics\Application\Queries\ListReportSourcesQuery;
use App\Modules\ReportingAndAnalytics\Application\Queries\PreviewReportQuery;
use App\Modules\ReportingAndAnalytics\Http\Requests\ListReportSourcesRequest;
use App\Modules\ReportingAndAnalytics\Http\Requests\PreviewReportRequest;
use App\Modules\ReportingAndAnalytics\Http\Resources\ReportResultResource;
use App\Modules\ReportingAndAnalytics\Http\Resources\ReportSourceResource;
use App\Support\PublicInputMapper;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class ReportSourceController
{
    public function index(ListReportSourcesRequest $request, ListReportSourcesQuery $query): AnonymousResourceCollection
    {
        /** @var User $actor */
        $actor = $request->user();

        return ReportSourceResource::collection($query->execute($actor));
    }

    public function preview(PreviewReportRequest $request, string $source, PreviewReportQuery $query): ReportResultResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new ReportResultResource($query->execute($source, PublicInputMapper::toInternal($request->safe()->all(), 'report'), $actor));
    }
}
