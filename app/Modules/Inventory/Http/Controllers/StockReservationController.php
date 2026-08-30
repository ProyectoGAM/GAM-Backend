<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Models\Inventory\StockReservation;
use App\Models\User;
use App\Modules\Inventory\Application\Actions\ConsumeStockReservationAction;
use App\Modules\Inventory\Application\Actions\ReleaseStockReservationAction;
use App\Modules\Inventory\Application\Actions\ReserveStockAction;
use App\Modules\Inventory\Http\Requests\ConsumeStockReservationRequest;
use App\Modules\Inventory\Http\Requests\ReleaseStockReservationRequest;
use App\Modules\Inventory\Http\Requests\ReserveStockRequest;
use App\Modules\Inventory\Http\Resources\StockReservationResource;
use App\Support\PublicInputMapper;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final readonly class StockReservationController
{
    public function store(ReserveStockRequest $request, ReserveStockAction $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return (new StockReservationResource($action->execute(PublicInputMapper::toInternal($request->validated(), 'inventory'), $actor)))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function release(ReleaseStockReservationRequest $request, StockReservation $stockReservation, ReleaseStockReservationAction $action): StockReservationResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new StockReservationResource($action->execute($stockReservation, PublicInputMapper::toInternal($request->validated(), 'inventory'), $actor));
    }

    public function consume(ConsumeStockReservationRequest $request, StockReservation $stockReservation, ConsumeStockReservationAction $action): StockReservationResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new StockReservationResource($action->execute($stockReservation, PublicInputMapper::toInternal($request->validated(), 'inventory'), $actor));
    }
}
