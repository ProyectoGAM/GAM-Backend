<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\ChangeFlockFeedingAction;
use App\Actions\Inventory\ReturnFlockFeedingToAutomaticAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\ChangeFlockFeedingRequest;
use App\Http\Requests\Inventory\ListFlockFeedingRequest;
use App\Http\Requests\Inventory\ReturnFlockFeedingToAutomaticRequest;
use App\Http\Resources\Inventory\FlockFeedingResource;
use App\Http\Resources\Lots\LotsOperationResource;
use App\Models\Inventory\FlockFeedingChange;
use App\Models\Lots\Flock;
use App\Queries\Inventory\GetFlockFeedingQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class FlockFeedingController extends Controller
{
    public function show(ListFlockFeedingRequest $request, Flock $flock, GetFlockFeedingQuery $query): FlockFeedingResource
    {
        return new FlockFeedingResource($query->execute($flock));
    }

    public function history(ListFlockFeedingRequest $request, Flock $flock): AnonymousResourceCollection
    {
        return FlockFeedingResource::collection(FlockFeedingChange::query()->where('flock_id', $flock->id)
            ->when($request->validated('desde'), fn ($query, $date) => $query->where('effective_date', '>=', $date))
            ->when($request->validated('hasta'), fn ($query, $date) => $query->where('effective_date', '<=', $date))
            ->orderByDesc('effective_date')->orderByDesc('id')->paginate((int) $request->validated('por_pagina', 25)));
    }

    public function manual(ChangeFlockFeedingRequest $request, Flock $flock, ChangeFlockFeedingAction $action): JsonResponse
    {
        return (new LotsOperationResource($action->execute($flock, $request->validated(), $request->actor())))->response()->setStatusCode(201);
    }

    public function automatic(ReturnFlockFeedingToAutomaticRequest $request, Flock $flock, ReturnFlockFeedingToAutomaticAction $action): JsonResponse
    {
        return (new LotsOperationResource($action->execute($flock, $request->validated('idempotency_key'), $request->actor())))->response()->setStatusCode(201);
    }
}
