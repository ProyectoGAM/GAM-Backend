<?php

namespace App\Http\Controllers\ReportingAndAnalytics;

use App\Actions\ReportingAndAnalytics\CreateReportPresetAction;
use App\Actions\ReportingAndAnalytics\UpdateReportPresetAction;
use App\Http\Requests\ReportingAndAnalytics\ListReportPresetsRequest;
use App\Http\Requests\ReportingAndAnalytics\ManageReportPresetRequest;
use App\Http\Requests\ReportingAndAnalytics\StoreReportPresetRequest;
use App\Http\Requests\ReportingAndAnalytics\UpdateReportPresetRequest;
use App\Http\Requests\ReportingAndAnalytics\ViewReportPresetRequest;
use App\Http\Resources\ReportingAndAnalytics\ReportPresetResource;
use App\Models\ReportingAndAnalytics\ReportPreset;
use App\Models\User;
use App\Queries\ReportingAndAnalytics\ListReportPresetsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final readonly class ReportPresetController
{
    public function index(ListReportPresetsRequest $request, ListReportPresetsQuery $query): AnonymousResourceCollection
    {
        /** @var User $actor */
        $actor = $request->user();

        return ReportPresetResource::collection($query->execute($actor, $request->validated()));
    }

    public function store(StoreReportPresetRequest $request, CreateReportPresetAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return (new ReportPresetResource($action->execute($request->validated(), $actor)))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(ViewReportPresetRequest $request, ReportPreset $reportPreset): ReportPresetResource
    {
        return new ReportPresetResource($reportPreset);
    }

    public function update(UpdateReportPresetRequest $request, ReportPreset $reportPreset, UpdateReportPresetAction $action): ReportPresetResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new ReportPresetResource($action->execute($reportPreset, $request->validated(), $actor));
    }

    public function destroy(ManageReportPresetRequest $request, ReportPreset $reportPreset): Response
    {
        $reportPreset->delete();

        return response()->noContent();
    }
}
