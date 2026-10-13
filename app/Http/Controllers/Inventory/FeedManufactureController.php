<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\CancelFeedManufactureAction;
use App\Actions\Inventory\ManufactureFeedAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\CancelFeedManufactureRequest;
use App\Http\Requests\Inventory\ListFeedOperationsRequest;
use App\Http\Requests\Inventory\ManufactureFeedRequest;
use App\Http\Requests\Inventory\ViewFeedOperationRequest;
use App\Http\Resources\Inventory\FeedStockOperationResource;
use App\Models\Inventory\FeedStockOperation;
use App\Queries\Inventory\ListFeedOperationsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class FeedManufactureController extends Controller
{
    public function index(ListFeedOperationsRequest $request, ListFeedOperationsQuery $query): AnonymousResourceCollection
    {
        return FeedStockOperationResource::collection($query->execute($request->validated()));
    }

    public function show(ViewFeedOperationRequest $request, FeedStockOperation $operation): FeedStockOperationResource
    {
        abort_unless($operation->kind === 'manufacture', 404);

        return new FeedStockOperationResource($operation->load('cancellation'));
    }

    public function store(ManufactureFeedRequest $request, ManufactureFeedAction $action): JsonResponse
    {
        $operation = $action->execute(
            (int) $request->validated('receta_id'), (int) $request->validated('planta_id'),
            $request->validated('cantidad_g'), $request->validated('idempotency_key'), $request->actor(),
        );

        return (new FeedStockOperationResource($operation))->response()->setStatusCode(201);
    }

    public function cancel(CancelFeedManufactureRequest $request, FeedStockOperation $operation, CancelFeedManufactureAction $action): FeedStockOperationResource
    {
        $action->execute((int) $operation->id, $request->validated('motivo'), $request->validated('idempotency_key'), $request->actor());

        return new FeedStockOperationResource($operation->fresh(['cancellation']));
    }
}
