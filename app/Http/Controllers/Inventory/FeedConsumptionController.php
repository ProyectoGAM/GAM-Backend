<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\CorrectFeedConsumptionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\CorrectFeedConsumptionRequest;
use App\Http\Requests\Inventory\ListFlockFeedingRequest;
use App\Http\Resources\Inventory\FeedConsumptionResource;
use App\Http\Resources\Lots\LotsOperationResource;
use App\Models\Inventory\FeedConsumption;
use App\Models\Lots\Flock;
use App\Queries\Inventory\ListFeedConsumptionsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class FeedConsumptionController extends Controller
{
    public function index(ListFlockFeedingRequest $request, Flock $flock, ListFeedConsumptionsQuery $query): AnonymousResourceCollection
    {
        $result = $query->execute($flock, $request->validated());

        return FeedConsumptionResource::collection($result['consumptions'])->additional([
            'acumulado_g' => $result['accumulated_grams'],
        ]);
    }

    public function show(ListFlockFeedingRequest $request, Flock $flock, FeedConsumption $consumption): FeedConsumptionResource
    {
        abort_unless($consumption->flock_id === $flock->id, 404);

        return new FeedConsumptionResource($consumption->load(['latestCorrection', 'corrections', 'movements']));
    }

    public function correct(CorrectFeedConsumptionRequest $request, Flock $flock, FeedConsumption $consumption, CorrectFeedConsumptionAction $action): JsonResponse
    {
        abort_unless($consumption->flock_id === $flock->id, 404);

        return (new LotsOperationResource($action->execute($consumption, $request->validated(), $request->actor())))->response()->setStatusCode(201);
    }
}
