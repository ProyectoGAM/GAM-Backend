<?php

namespace App\Http\Controllers\CustomersAndAccounts;

use App\Actions\CustomersAndAccounts\AdjustCustomerAccountAction;
use App\Actions\CustomersAndAccounts\RecordCustomerPaymentAction;
use App\Actions\CustomersAndAccounts\ReverseCustomerMovementAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomersAndAccounts\AdjustCustomerAccountRequest;
use App\Http\Requests\CustomersAndAccounts\ListCustomerMovementsRequest;
use App\Http\Requests\CustomersAndAccounts\RecordCustomerPaymentRequest;
use App\Http\Requests\CustomersAndAccounts\ReverseCustomerMovementRequest;
use App\Http\Resources\CustomersAndAccounts\CustomerAccountResource;
use App\Http\Resources\CustomersAndAccounts\CustomerMovementResource;
use App\Models\CustomersAndAccounts\Customer;
use App\Models\CustomersAndAccounts\CustomerAccountMovement;
use App\Models\CustomersAndAccounts\CustomerOperation;
use App\Queries\CustomersAndAccounts\CustomerAccountHistoryQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CustomerAccountController extends Controller
{
    public function show(ListCustomerMovementsRequest $request, Customer $cliente): CustomerAccountResource
    {
        return new CustomerAccountResource($cliente->account()->firstOrFail());
    }

    public function index(ListCustomerMovementsRequest $request, Customer $cliente, CustomerAccountHistoryQuery $query): AnonymousResourceCollection
    {
        $result = $query->execute($cliente, $request->validated());

        return CustomerMovementResource::collection($result['rows'])->additional(['statement' => $result['statement']]);
    }

    public function pay(RecordCustomerPaymentRequest $request, Customer $cliente, RecordCustomerPaymentAction $action): AnonymousResourceCollection
    {
        return $this->result($action->execute($cliente, $request->user(), $request->payload(), $request->key()));
    }

    public function adjust(AdjustCustomerAccountRequest $request, Customer $cliente, AdjustCustomerAccountAction $action): AnonymousResourceCollection
    {
        return $this->result($action->execute($cliente, $request->user(), $request->payload(), $request->key()));
    }

    public function reverse(ReverseCustomerMovementRequest $request, Customer $cliente, CustomerAccountMovement $movimiento, ReverseCustomerMovementAction $action): AnonymousResourceCollection
    {
        return $this->result($action->execute($cliente, $movimiento, $request->user(), $request->validated('reason'), $request->key()));
    }

    private function result(CustomerOperation $operation): AnonymousResourceCollection
    {
        return CustomerMovementResource::collection($operation->movements()->with(['account.customer', 'operation', 'delivery', 'reversal', 'original'])->get())
            ->additional(['operation_id' => $operation->operation_id]);
    }
}
