<?php

namespace App\Http\Controllers\ReportingAndAnalytics;

use App\Http\Requests\ReportingAndAnalytics\ListReportSourcesRequest;
use App\Http\Requests\ReportingAndAnalytics\PreviewReportRequest;
use App\Http\Resources\ReportingAndAnalytics\ReportResultResource;
use App\Http\Resources\ReportingAndAnalytics\ReportSourceResource;
use App\Models\User;
use App\Queries\ReportingAndAnalytics\ListReportSourcesQuery;
use App\Queries\ReportingAndAnalytics\PreviewReportQuery;
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

        return new ReportResultResource($query->execute($source, $request->safe()->all(), $actor));
    }
}
