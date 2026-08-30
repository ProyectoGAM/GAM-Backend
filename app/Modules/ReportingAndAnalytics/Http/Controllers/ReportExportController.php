<?php

namespace App\Modules\ReportingAndAnalytics\Http\Controllers;

use App\Models\ReportingAndAnalytics\ReportExport;
use App\Models\User;
use App\Modules\ReportingAndAnalytics\Application\Actions\CreateTemporaryReportLinkAction;
use App\Modules\ReportingAndAnalytics\Application\Actions\DownloadReportExportAction;
use App\Modules\ReportingAndAnalytics\Application\Actions\RequestReportExportAction;
use App\Modules\ReportingAndAnalytics\Application\Queries\ListReportExportsQuery;
use App\Modules\ReportingAndAnalytics\Domain\Enums\ReportExportFormat;
use App\Modules\ReportingAndAnalytics\Http\Requests\DownloadReportExportRequest;
use App\Modules\ReportingAndAnalytics\Http\Requests\ListReportExportsRequest;
use App\Modules\ReportingAndAnalytics\Http\Requests\StoreReportExportRequest;
use App\Modules\ReportingAndAnalytics\Http\Requests\StoreTemporaryReportLinkRequest;
use App\Modules\ReportingAndAnalytics\Http\Requests\ViewReportExportRequest;
use App\Modules\ReportingAndAnalytics\Http\Resources\ReportExportResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final readonly class ReportExportController
{
    public function store(StoreReportExportRequest $request, string $source, RequestReportExportAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $attributes = $request->safe()->except(['format', 'idempotency_key']);
        $result = $action->execute(
            sourceKey: $source,
            input: $attributes,
            format: ReportExportFormat::from((string) $request->validated('format')),
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

        return ReportExportResource::collection($query->execute($actor, $request->validated()));
    }

    public function show(ViewReportExportRequest $request, ReportExport $reportExport): ReportExportResource
    {
        return new ReportExportResource($reportExport);
    }

    public function download(DownloadReportExportRequest $request, ReportExport $reportExport, DownloadReportExportAction $action): Response
    {
        return $action->execute($reportExport);
    }

    public function share(StoreTemporaryReportLinkRequest $request, ReportExport $reportExport, CreateTemporaryReportLinkAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $link = $action->execute($reportExport, (int) $request->validated('expires_in'), $actor);

        return response()->json($link, Response::HTTP_CREATED);
    }
}
