<?php

namespace App\Http\Controllers\ManagementPlans;

use App\Actions\ManagementPlans\ReviseFlockPlanAction;
use App\Http\Requests\ManagementPlans\AssignFlockPlanRequest;
use App\Http\Requests\ManagementPlans\ReviseFlockPlanRequest;
use App\Http\Requests\ManagementPlans\ViewFlockPlanRequest;
use App\Http\Resources\ManagementPlans\FlockPlanResource;
use App\Models\Lots\Flock;
use App\Models\ManagementPlans\FlockPlan;
use Illuminate\Http\JsonResponse;

final readonly class FlockPlanController
{
    public function show(ViewFlockPlanRequest $request, Flock $flock, ReviseFlockPlanAction $action): FlockPlanResource
    {
        $plan = FlockPlan::query()->where('flock_id', $flock->id)->firstOrFail();

        return new FlockPlanResource($action->snapshot($plan, (bool) ($request->validated()['include_revisions'] ?? false)));
    }

    public function assign(AssignFlockPlanRequest $request, Flock $flock, ReviseFlockPlanAction $action): JsonResponse
    {
        $operation = $action->assignLegacy($flock, $request->attributesForAction(), $request->actor());

        return (new FlockPlanResource([...$operation->result['plan'], 'operation_id' => $operation->operation_id]))->response()->setStatusCode(201);
    }

    public function update(ReviseFlockPlanRequest $request, Flock $flock, ReviseFlockPlanAction $action): FlockPlanResource
    {
        $operation = $action->revise($flock, $request->attributesForAction(), $request->actor());

        return new FlockPlanResource([...$operation->result['plan'], 'operation_id' => $operation->operation_id]);
    }
}
