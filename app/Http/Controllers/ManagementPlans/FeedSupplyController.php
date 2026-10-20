<?php

namespace App\Http\Controllers\ManagementPlans;

use App\Actions\ManagementPlans\RecordFeedSupplyAction;
use App\Http\Requests\ManagementPlans\ListFeedSuppliesRequest;
use App\Http\Requests\ManagementPlans\StoreFeedSupplyRequest;
use App\Http\Resources\Lots\LotsOperationResource;
use App\Http\Resources\ManagementPlans\FeedSupplyResource;
use App\Models\Lots\Flock;
use App\Models\ManagementPlans\FeedSupply;
use App\Models\SuppliersAndCatalogs\Product;
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
        $query = Product::query()->where('kind', 'finished_feed')->where('status', 'active');
        if ($request->filled('search')) {
            $query->where('name', 'ilike', '%'.$request->validated('search').'%');
        }
        $page = $query->orderBy('name')->orderBy('id')->paginate(50, ['id', 'name']);

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
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
