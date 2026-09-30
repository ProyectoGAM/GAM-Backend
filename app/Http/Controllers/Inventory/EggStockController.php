<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\CancelEggStockTransactionAction;
use App\Actions\Inventory\CorrectEggStockTransactionAction;
use App\Actions\Inventory\RecordManualEggStockAction;
use App\Http\Requests\Inventory\CancelEggStockTransactionRequest;
use App\Http\Requests\Inventory\CorrectEggStockTransactionRequest;
use App\Http\Requests\Inventory\ListEggStockTransactionsRequest;
use App\Http\Requests\Inventory\StoreEggStockIssueRequest;
use App\Http\Requests\Inventory\StoreEggStockReceiptRequest;
use App\Http\Requests\Inventory\ViewEggStockRequest;
use App\Http\Resources\Inventory\EggStockTransactionResource;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\EggStockTransaction;
use App\Queries\Inventory\GetEggStockBalanceQuery;
use App\Queries\Inventory\GetEggStockTransactionQuery;
use App\Queries\Inventory\ListEggStockTransactionsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class EggStockController
{
    public function balance(ViewEggStockRequest $request, ProductionUnit $productionUnit, GetEggStockBalanceQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->execute($productionUnit)]);
    }

    public function index(ListEggStockTransactionsRequest $request, ProductionUnit $productionUnit, ListEggStockTransactionsQuery $query): AnonymousResourceCollection
    {
        return EggStockTransactionResource::collection($query->execute($productionUnit, $request->attributesForAction()));
    }

    public function show(ViewEggStockRequest $request, EggStockTransaction $movement, GetEggStockTransactionQuery $query): EggStockTransactionResource
    {
        return new EggStockTransactionResource($query->execute($movement));
    }

    public function receipt(StoreEggStockReceiptRequest $request, ProductionUnit $productionUnit, RecordManualEggStockAction $action): JsonResponse
    {
        $result = $action->execute($productionUnit, $request->attributesForAction(), $request->actor());

        return response()->json(['data' => $result], 201);
    }

    public function issue(StoreEggStockIssueRequest $request, ProductionUnit $productionUnit, RecordManualEggStockAction $action): JsonResponse
    {
        $data = $request->attributesForAction();
        $result = $action->execute($productionUnit, $data, $request->actor(), -1, (string) $data['type']);

        return response()->json(['data' => $result], 201);
    }

    public function update(CorrectEggStockTransactionRequest $request, EggStockTransaction $movement, CorrectEggStockTransactionAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($movement, $request->attributesForAction(), $request->actor())]);
    }

    public function cancel(CancelEggStockTransactionRequest $request, EggStockTransaction $movement, CancelEggStockTransactionAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($movement, $request->attributesForAction(), $request->actor())]);
    }
}
