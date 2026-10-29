<?php

namespace App\Http\Controllers\Deliveries;

use App\Actions\CustomersAndAccounts\RecordCustomerPaymentAction;
use App\Actions\Deliveries\AddDeliveryLoadAction;
use App\Actions\Deliveries\CloseDeliveryAction;
use App\Actions\Deliveries\PublishDeliveryLocationsAction;
use App\Actions\Deliveries\RecordDeliveryStopAction;
use App\Actions\Deliveries\StartDeliveryAction;
use App\Actions\IdentityAndAccess\AuthenticateSharedPinAction;
use App\Enums\Deliveries\DeliveryStatus;
use App\Enums\FarmStructure\ProductionUnitStatus;
use App\Exceptions\IdentityAndAccess\IdentityException;
use App\Http\Requests\Deliveries\CloseDeliveryRequest;
use App\Http\Requests\Deliveries\CurrentDeliveriesRequest;
use App\Http\Requests\Deliveries\ListDeliveriesRequest;
use App\Http\Requests\Deliveries\ListDeliveryClientsRequest;
use App\Http\Requests\Deliveries\ListDeliveryProductionUnitsRequest;
use App\Http\Requests\Deliveries\ShowDeliveryRequest;
use App\Http\Requests\Deliveries\StartDeliveryRequest;
use App\Http\Requests\Deliveries\StoreDeliveryLoadRequest;
use App\Http\Requests\Deliveries\StoreDeliveryLocationsRequest;
use App\Http\Requests\Deliveries\StoreDeliveryPaymentRequest;
use App\Http\Requests\Deliveries\StoreDeliveryStopRequest;
use App\Http\Resources\CustomersAndAccounts\CustomerMovementResource;
use App\Http\Resources\CustomersAndAccounts\DeliveryClientResource;
use App\Http\Resources\Deliveries\DeliveryResource;
use App\Http\Resources\Deliveries\DeliveryStopResource;
use App\Models\CustomersAndAccounts\Customer;
use App\Models\Deliveries\Delivery;
use App\Models\FarmStructure\ProductionUnit;
use App\Queries\CustomersAndAccounts\ListCustomersQuery;
use App\Queries\Deliveries\ListDeliveriesQuery;
use App\Queries\Deliveries\ShowDeliveryQuery;
use App\Services\Deliveries\DeliveryLoadUnits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final readonly class DeliveryController
{
    public function clients(ListDeliveryClientsRequest $request, ListCustomersQuery $query): AnonymousResourceCollection
    {
        return DeliveryClientResource::collection($query->operational($request->validated()));
    }

    public function payment(StoreDeliveryPaymentRequest $request, Delivery $reparto, RecordCustomerPaymentAction $action): AnonymousResourceCollection
    {
        $data = $request->attributesForAction();
        $customer = Customer::query()->where('public_id', $data['client_reference'])->firstOrFail();
        unset($data['client_reference'], $data['idempotency_key']);
        $operation = $action->execute($customer, $request->actor(), $data, $request->idempotencyKey(), $reparto);

        return CustomerMovementResource::collection($operation->movements()->with(['account.customer', 'operation', 'delivery', 'original', 'reversal'])->get());
    }

    public function units(ListDeliveryClientsRequest $request, DeliveryLoadUnits $units): JsonResponse
    {
        return response()->json(['data' => $units->catalog()]);
    }

    public function productionUnits(ListDeliveryProductionUnitsRequest $request): JsonResponse
    {
        $units = ProductionUnit::query()->where('status', ProductionUnitStatus::Active)
            ->when($request->validated('search'), fn ($query, string $search) => $query->where('name', 'ilike', '%'.$search.'%'))
            ->orderBy('name')->orderBy('id')->limit((int) $request->validated('limit', 1000))->get(['id', 'name']);

        return response()->json(['data' => $units]);
    }

    public function store(StartDeliveryRequest $request, StartDeliveryAction $action, AuthenticateSharedPinAction $pinAuth, DeliveryLoadUnits $units): JsonResponse
    {
        $this->confirmDriverPin($request, $pinAuth);
        $delivery = $action->execute(
            $request->actor(),
            $request->attributesForAction(),
            $request->idempotencyKey(),
        );

        return (new DeliveryResource($delivery))->additional(['catalog' => $units->catalog()])
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
