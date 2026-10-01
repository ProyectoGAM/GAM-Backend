<?php

namespace App\Http\Controllers\Deliveries;

use App\Actions\Deliveries\AddDeliveryLoadAction;
use App\Actions\Deliveries\CloseDeliveryAction;
use App\Actions\Deliveries\PublishDeliveryLocationsAction;
use App\Actions\Deliveries\RecordDeliveryStopAction;
use App\Actions\Deliveries\StartDeliveryAction;
use App\Actions\IdentityAndAccess\AuthenticateSharedPinAction;
use App\Enums\Deliveries\DeliveryStatus;
use App\Exceptions\IdentityAndAccess\IdentityException;
use App\Http\Requests\Deliveries\CloseDeliveryRequest;
use App\Http\Requests\Deliveries\CurrentDeliveriesRequest;
use App\Http\Requests\Deliveries\ListDeliveriesRequest;
use App\Http\Requests\Deliveries\ListDeliveryClientsRequest;
use App\Http\Requests\Deliveries\ShowDeliveryRequest;
use App\Http\Requests\Deliveries\StartDeliveryRequest;
use App\Http\Requests\Deliveries\StoreDeliveryLoadRequest;
use App\Http\Requests\Deliveries\StoreDeliveryLocationsRequest;
use App\Http\Requests\Deliveries\StoreDeliveryStopRequest;
use App\Http\Resources\Deliveries\DeliveryResource;
use App\Http\Resources\Deliveries\DeliveryStopResource;
use App\Models\Deliveries\Delivery;
use App\Queries\Deliveries\ListDeliveriesQuery;
use App\Queries\Deliveries\ShowDeliveryQuery;
use App\Services\Deliveries\DeliveryLoadUnits;
use App\Services\Deliveries\LocalDeliveryClientCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final readonly class DeliveryController
{
    public function clients(ListDeliveryClientsRequest $request, LocalDeliveryClientCatalog $catalog): JsonResponse
    {
        return response()->json(['data' => $catalog->search(
            trim((string) $request->validated('search', '')),
            (int) $request->validated('limit', 50),
        )]);
    }

    public function units(ListDeliveryClientsRequest $request, DeliveryLoadUnits $units): JsonResponse
    {
        return response()->json(['data' => $units->catalog()]);
    }

    public function store(StartDeliveryRequest $request, StartDeliveryAction $action, AuthenticateSharedPinAction $pinAuth): JsonResponse
    {
        $this->confirmDriverPin($request, $pinAuth);
        $delivery = $action->execute(
            $request->actor(),
            $request->attributesForAction(),
            $request->idempotencyKey(),
        );

        return (new DeliveryResource($delivery))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function current(CurrentDeliveriesRequest $request, ListDeliveriesQuery $query): AnonymousResourceCollection
    {
        return DeliveryResource::collection($query->execute($request->filters(), DeliveryStatus::Active));
    }

    public function index(ListDeliveriesRequest $request, ListDeliveriesQuery $query): AnonymousResourceCollection
    {
        return DeliveryResource::collection($query->execute($request->filters()));
    }

    public function show(ShowDeliveryRequest $request, Delivery $reparto, ShowDeliveryQuery $query): DeliveryResource
    {
        return new DeliveryResource($query->execute($reparto));
    }

    public function stop(StoreDeliveryStopRequest $request, Delivery $reparto, RecordDeliveryStopAction $action): DeliveryStopResource
    {
        return new DeliveryStopResource($action->execute(
            $reparto,
            $request->actor(),
            $request->attributesForAction(),
            $request->idempotencyKey(),
        ));
    }

    public function load(StoreDeliveryLoadRequest $request, Delivery $reparto, AddDeliveryLoadAction $action): DeliveryResource
    {
        return new DeliveryResource($action->execute(
            $reparto,
            $request->actor(),
            $request->attributesForAction(),
            $request->idempotencyKey(),
        ));
    }

    public function locations(StoreDeliveryLocationsRequest $request, Delivery $reparto, PublishDeliveryLocationsAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->execute($reparto, $request->actor(), $request->attributesForAction()['locations']),
        ]);
    }

    public function close(CloseDeliveryRequest $request, Delivery $reparto, CloseDeliveryAction $action, AuthenticateSharedPinAction $pinAuth): DeliveryResource
    {
        $this->confirmDriverPin($request, $pinAuth);

        return new DeliveryResource($action->execute(
            $reparto,
            $request->actor(),
            $request->attributesForAction(),
            $request->idempotencyKey(),
        ));
    }

    private function confirmDriverPin(StartDeliveryRequest|CloseDeliveryRequest $request, AuthenticateSharedPinAction $pinAuth): void
    {
        $actor = $request->actor();
        if (! $actor->pin_enabled) {
            throw new IdentityException(422, 'PIN_NOT_CONFIGURED', 'Pedí a administración que configure tu PIN de repartidor.');
        }

        $pinAuth->verifyPin($actor, (string) $request->validated('pin'));
    }
}
