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
    public function balance(ViewEggStockRequest $request, ProductionUnit $unidadProductiva, GetEggStockBalanceQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->execute($unidadProductiva)]);
    }

    public function index(ListEggStockTransactionsRequest $request, ProductionUnit $unidadProductiva, ListEggStockTransactionsQuery $query): AnonymousResourceCollection
    {
        return EggStockTransactionResource::collection($query->execute($unidadProductiva, $request->attributesForAction()));
    }

    public function show(ViewEggStockRequest $request, EggStockTransaction $movimiento, GetEggStockTransactionQuery $query): EggStockTransactionResource
    {
        return new EggStockTransactionResource($query->execute($movimiento));
    }

    public function receipt(StoreEggStockReceiptRequest $request, ProductionUnit $unidadProductiva, RecordManualEggStockAction $action): JsonResponse
    {
        $result = $action->execute($unidadProductiva, $request->attributesForAction(), $request->actor());

        return response()->json(['data' => $result], 201);
    }

    public function issue(StoreEggStockIssueRequest $request, ProductionUnit $unidadProductiva, RecordManualEggStockAction $action): JsonResponse
    {
        $data = $request->attributesForAction();
        $result = $action->execute($unidadProductiva, $data, $request->actor(), -1, (string) $data['type']);

        return response()->json(['data' => $result], 201);
    }

    public function update(CorrectEggStockTransactionRequest $request, EggStockTransaction $movimiento, CorrectEggStockTransactionAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($movimiento, $request->attributesForAction(), $request->actor())]);
    }

    public function cancel(CancelEggStockTransactionRequest $request, EggStockTransaction $movimiento, CancelEggStockTransactionAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($movimiento, $request->attributesForAction(), $request->actor())]);
    }
}
