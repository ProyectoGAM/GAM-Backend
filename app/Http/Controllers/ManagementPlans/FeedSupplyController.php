<?php

namespace App\Http\Controllers\ManagementPlans;

use App\Actions\ManagementPlans\RecordFeedSupplyAction;
use App\Http\Requests\ManagementPlans\ListFeedSuppliesRequest;
use App\Http\Requests\ManagementPlans\StoreFeedSupplyRequest;
use App\Http\Resources\Lots\LotsOperationResource;
use App\Http\Resources\ManagementPlans\FeedSupplyResource;
use App\Models\Lots\Flock;
use App\Models\ManagementPlans\FeedSupply;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class FeedSupplyController
{
    public function index(ListFeedSuppliesRequest $request, Flock $flock): AnonymousResourceCollection
    {
        return FeedSupplyResource::collection(FeedSupply::query()->where('flock_id', $flock->id)
            ->orderByDesc('occurred_at')->orderByDesc('id')->paginate((int) $request->input('per_page', 20)));
    }

    public function products(ListFeedSuppliesRequest $request): JsonResponse
    {
        return response()->json(['data' => [], 'meta' => ['current_page' => (int) $request->validated('page', 1), 'last_page' => 1]]);
    }

    public function store(StoreFeedSupplyRequest $request, Flock $flock, RecordFeedSupplyAction $action): JsonResponse
    {
        return (new LotsOperationResource($action->execute($flock, $request->attributesForAction(), $request->actor())))->response()->setStatusCode(201);
    }

    public function cancel(StoreFeedSupplyRequest $request, Flock $flock, FeedSupply $feedSupply, RecordFeedSupplyAction $action): JsonResponse
    {
        return (new LotsOperationResource($action->cancel($flock, $feedSupply, $request->attributesForAction(), $request->actor())))->response();
    }
}
