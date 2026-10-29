<?php

namespace App\Http\Controllers\CustomersAndAccounts;

use App\Actions\CustomersAndAccounts\ChangeCustomerStatusAction;
use App\Actions\CustomersAndAccounts\SaveCustomerAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomersAndAccounts\ChangeCustomerStatusRequest;
use App\Http\Requests\CustomersAndAccounts\CustomerMapRequest;
use App\Http\Requests\CustomersAndAccounts\ListCustomersRequest;
use App\Http\Requests\CustomersAndAccounts\SaveCustomerRequest;
use App\Http\Requests\CustomersAndAccounts\ShowCustomerRequest;
use App\Http\Resources\CustomersAndAccounts\CustomerResource;
use App\Http\Resources\Deliveries\DeliveryStopResource;
use App\Models\CustomersAndAccounts\Customer;
use App\Queries\CustomersAndAccounts\ListCustomersQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CustomerController extends Controller
{
    public function index(ListCustomersRequest $request, ListCustomersQuery $query): AnonymousResourceCollection
    {
        return CustomerResource::collection($query->execute($request->validated()));
    }

    public function map(CustomerMapRequest $request, ListCustomersQuery $query): JsonResponse
    {
        return response()->json($query->markers($request->validated()));
    }

    public function store(SaveCustomerRequest $request, SaveCustomerAction $action): JsonResponse
    {
        $operation = $action->execute(null, $request->user(), $request->payload(), $request->key());

        return (new CustomerResource($operation->result['customer']))->response()->setStatusCode(201);
    }

    public function show(ShowCustomerRequest $request, Customer $cliente): CustomerResource
    {
        return new CustomerResource($cliente->load(['account', 'locality.department']));
    }

    public function update(SaveCustomerRequest $request, Customer $cliente, SaveCustomerAction $action): CustomerResource
    {
        $operation = $action->execute($cliente, $request->user(), $request->payload(), $request->key());

        return new CustomerResource($operation->result['customer']);
    }

    public function status(ChangeCustomerStatusRequest $request, Customer $cliente, ChangeCustomerStatusAction $action): CustomerResource
    {
        $operation = $action->execute($cliente, $request->user(), $request->validated('status'), $request->key());

        return new CustomerResource($operation->result['customer']);
    }

    public function deliveries(ShowCustomerRequest $request, Customer $cliente): AnonymousResourceCollection
    {
        return DeliveryStopResource::collection($cliente->stops()->with('delivery')->orderByDesc('visited_at')->orderByDesc('id')
            ->paginate($request->validated('per_page', 50))->withQueryString());
    }
}
