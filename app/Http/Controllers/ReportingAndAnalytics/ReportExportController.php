<?php

namespace App\Http\Controllers\ReportingAndAnalytics;

use App\Actions\ReportingAndAnalytics\CreateTemporaryReportLinkAction;
use App\Actions\ReportingAndAnalytics\DownloadReportExportAction;
use App\Actions\ReportingAndAnalytics\RequestReportExportAction;
use App\Enums\ReportingAndAnalytics\ReportExportFormat;
use App\Http\Requests\ReportingAndAnalytics\DownloadReportExportRequest;
use App\Http\Requests\ReportingAndAnalytics\ListReportExportsRequest;
use App\Http\Requests\ReportingAndAnalytics\StoreReportExportRequest;
use App\Http\Requests\ReportingAndAnalytics\StoreTemporaryReportLinkRequest;
use App\Http\Requests\ReportingAndAnalytics\ViewReportExportRequest;
use App\Http\Resources\ReportingAndAnalytics\ReportExportResource;
use App\Models\ReportingAndAnalytics\ReportExport;
use App\Models\User;
use App\Queries\ReportingAndAnalytics\ListReportExportsQuery;
use App\Support\PublicInputMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final readonly class ReportExportController
{
    public function store(StoreReportExportRequest $request, string $source, RequestReportExportAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $attributes = PublicInputMapper::toInternal(
            $request->safe()->except(['formato', 'idempotency_key']),
            'report-query',
        );
        $result = $action->execute(
            sourceKey: $source,
            input: $attributes,
            format: ReportExportFormat::from((string) $request->validated('formato')),
            idempotencyKey: (string) $request->validated('idempotency_key'),
            actor: $actor,
        );

        return (new ReportExportResource($result['export']))
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    public function index(ListReportExportsRequest $request, ListReportExportsQuery $query): AnonymousResourceCollection
    {
        /** @var User $actor */
        $actor = $request->user();

        return ReportExportResource::collection($query->execute($actor, PublicInputMapper::toInternal($request->validated(), 'report')));
    }

    public function show(ViewReportExportRequest $request, ReportExport $reportExport): ReportExportResource
    {
        return new ReportExportResource($reportExport);
    }

    public function download(DownloadReportExportRequest $request, ReportExport $reportExport, DownloadReportExportAction $action): Response
    {
        return $action->execute($reportExport, $request->query('view') === 'html');
    }

    public function share(StoreTemporaryReportLinkRequest $request, ReportExport $reportExport, CreateTemporaryReportLinkAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $link = $action->execute($reportExport, (int) $request->validated('expires_in'), $actor);

        return response()->json($link, Response::HTTP_CREATED);
    }
}
