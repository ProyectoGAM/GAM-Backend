<?php

namespace App\Modules\ReportingAndAnalytics\Http\Controllers;

use App\Models\ReportingAndAnalytics\ReportPreset;
use App\Models\User;
use App\Modules\ReportingAndAnalytics\Application\Actions\CreateReportPresetAction;
use App\Modules\ReportingAndAnalytics\Application\Actions\UpdateReportPresetAction;
use App\Modules\ReportingAndAnalytics\Application\Queries\ListReportPresetsQuery;
use App\Modules\ReportingAndAnalytics\Http\Requests\ListReportPresetsRequest;
use App\Modules\ReportingAndAnalytics\Http\Requests\ManageReportPresetRequest;
use App\Modules\ReportingAndAnalytics\Http\Requests\StoreReportPresetRequest;
use App\Modules\ReportingAndAnalytics\Http\Requests\UpdateReportPresetRequest;
use App\Modules\ReportingAndAnalytics\Http\Requests\ViewReportPresetRequest;
use App\Modules\ReportingAndAnalytics\Http\Resources\ReportPresetResource;
use App\Support\PublicInputMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final readonly class ReportPresetController
{
    public function index(ListReportPresetsRequest $request, ListReportPresetsQuery $query): AnonymousResourceCollection
    {
        /** @var User $actor */
        $actor = $request->user();

        return ReportPresetResource::collection($query->execute($actor, PublicInputMapper::toInternal($request->validated(), 'report')));
    }

    public function store(StoreReportPresetRequest $request, CreateReportPresetAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return (new ReportPresetResource($action->execute(PublicInputMapper::toInternal($request->validated(), 'report'), $actor)))
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

        return new ReportPresetResource($action->execute($reportPreset, PublicInputMapper::toInternal($request->validated(), 'report'), $actor));
    }

    public function destroy(ManageReportPresetRequest $request, ReportPreset $reportPreset): Response
    {
        $reportPreset->delete();

        return response()->noContent();
    }
}
